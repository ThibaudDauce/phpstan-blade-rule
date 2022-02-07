<?php

declare(strict_types=1);

namespace Vural\PHPStanBladeRule\Compiler;

use Illuminate\Contracts\View\Factory;
use Illuminate\Events\Dispatcher;
use Illuminate\Events\NullDispatcher;
use Illuminate\Foundation\Application;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\FileViewFinder;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\ShouldNotHappenException;
use Symplify\TemplatePHPStanCompiler\NodeFactory\VarDocNodeFactory;
use Symplify\TemplatePHPStanCompiler\ValueObject\VariableAndType;
use Vural\PHPStanBladeRule\Blade\PhpLineToTemplateLineResolver;
use Vural\PHPStanBladeRule\PHPParser\NodeVisitor\AddLoopVarTypeToForeachNodeVisitor;
use Vural\PHPStanBladeRule\PHPParser\NodeVisitor\RemoveEnvVariableNodeVisitor;
use Vural\PHPStanBladeRule\PHPParser\NodeVisitor\RemoveEscapeFunctionNodeVisitor;
use Vural\PHPStanBladeRule\ValueObject\BladeInclude;
use Vural\PHPStanBladeRule\ValueObject\PhpFileContentsWithLineMap;

use function array_merge;
use function getcwd;
use function preg_match_all;
use function preg_quote;
use function preg_replace;
use function sprintf;

final class BladeToPHPCompiler
{
    /**
     * @see https://regex101.com/r/BGw7Lf/1
     */
    private const VIEW_INCLUDE_REGEX = '#\$__env->make\(\'(.*?)\',( \[(.*?)?],)? \\\Illuminate\\\Support\\\Arr::except\(get_defined_vars\(\), \[\'__data\', \'__path\']\)\)->render\(\)#s';

    /**
     * @see https://regex101.com/r/BGw7Lf/1
     */
    private const VIEW_INCLUDE_REPLACE_REGEX = '#echo \$__env->make\(\'%s\',( \[(.*?)?],)? \\\Illuminate\\\Support\\\Arr::except\(get_defined_vars\(\), \[\'__data\', \'__path\']\)\)->render\(\);#s';

    private Parser $parser;

    /**
     * @param string[] $components
     * @phpstan-param array<int, array{class: string, alias: string, prefix: string}> $components
     */
    public function __construct(
        private IncludeCompiler $includeCompiler,
        private BladeCompiler $compiler,
        private Standard $printerStandard,
        private VarDocNodeFactory $varDocNodeFactory,
        private FileViewFinder $fileViewFinder,
        private FileNameAndLineNumberAddingPreCompiler $preCompiler,
        private PhpLineToTemplateLineResolver $phpLineToTemplateLineResolver,
        private PhpContentExtractor $phpContentExtractor,
        private array $components = [],
    ) {
        $parserFactory = new ParserFactory();
        $this->parser  = $parserFactory->create(ParserFactory::PREFER_PHP7);

        // Disable component rendering
        $this->compiler->withoutComponentTags();

        //$this->setupBladeComponents();
    }

    /**
     * @param array<VariableAndType> $variablesAndTypes
     *
     * @throws ShouldNotHappenException
     */
    public function compileContent(string $filePath, string $fileContents, array $variablesAndTypes): PhpFileContentsWithLineMap
    {
        // Precompile contents to add template file name and line numbers
        $fileContents = $this->preCompiler->setFileName($filePath)->compileString($fileContents);

        // Extract PHP content from HTML and PHP mixed content
        $rawPhpContent = $this->phpContentExtractor->extract($this->compiler->compileString($fileContents));

        $includes = $this->getIncludes($rawPhpContent);

        // Recursively fetch and compile includes
        while ($includes !== []) {
            foreach ($includes as $include) {
                $includedContent = $this->includeCompiler->decorateInclude($include);

                $rawPhpContent = preg_replace(sprintf(self::VIEW_INCLUDE_REPLACE_REGEX, preg_quote($include->name)), $includedContent, $rawPhpContent) ?? $rawPhpContent;
            }

            $includes = $this->getIncludes($rawPhpContent);
        }

        $decoratedPhpContent     = $this->decoratePhpContent($rawPhpContent, $variablesAndTypes);
        $phpLinesToTemplateLines = $this->phpLineToTemplateLineResolver->resolve($decoratedPhpContent);

        return new PhpFileContentsWithLineMap($decoratedPhpContent, $phpLinesToTemplateLines);
    }

    /**
     * @param VariableAndType[] $variablesAndTypes
     *
     * @throws ShouldNotHappenException
     */
    private function decoratePhpContent(string $phpContent, array $variablesAndTypes): string
    {
        $stmts = $this->parser->parse($phpContent);
        if ($stmts === null) {
            // TODO create our own exception
            throw new ShouldNotHappenException();
        }

        // Apply some visitors
        // - get rid of $__env variables
        // - get rid of e() function calls
        $stmts = $this->traverseStmtsWithVisitors($stmts, [
            new RemoveEnvVariableNodeVisitor(),
            new RemoveEscapeFunctionNodeVisitor(),
            new AddLoopVarTypeToForeachNodeVisitor(),
        ]);

        // Add @var docs to top of file
        $stmts = array_merge($this->varDocNodeFactory->createDocNodes($variablesAndTypes), $stmts);

        return $this->printerStandard->prettyPrintFile($stmts);
    }

    /**
     * @param Stmt[]                $stmts
     * @param NodeVisitorAbstract[] $nodeVisitors
     *
     * @return Node[]
     */
    private function traverseStmtsWithVisitors(array $stmts, array $nodeVisitors): array
    {
        $nodeTraverser = new NodeTraverser();
        foreach ($nodeVisitors as $nodeVisitor) {
            $nodeTraverser->addVisitor($nodeVisitor);
        }

        return $nodeTraverser->traverse($stmts);
    }

    /** @return BladeInclude[] */
    private function getIncludes(string $compiled): array
    {
        preg_match_all(self::VIEW_INCLUDE_REGEX, $compiled, $matches);

        $includes = [];
        foreach ($matches[1] as $i => $name) {
            $includes[] = new BladeInclude($name, $matches[2][$i]);
        }

        return $includes;
    }

    private function setupBladeComponents(): void
    {
        $currentWorkingDirectory = getcwd();

        if ($currentWorkingDirectory === false) {
            return;
        }

        //Hack to make the compiler work
        $application = new Application($currentWorkingDirectory);
        $application->bind(\Illuminate\Contracts\Foundation\Application::class, static fn () => $application);
        $application->bind(Factory::class, fn () => new \Illuminate\View\Factory(new EngineResolver(), $this->fileViewFinder, new NullDispatcher(new Dispatcher())));

        $application->alias('view', 'foo');

        //Register components
        foreach ($this->components as $component) {
            $this->compiler->component($component['class'], $component['alias'], $component['prefix']);
        }
    }
}

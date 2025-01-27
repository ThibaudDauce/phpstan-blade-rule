<?php

declare(strict_types=1);

namespace Vural\PHPStanBladeRule\Tests\Rules;

use PHPStan\Rules\Cast\EchoRule;
use PHPStan\Rules\Operators\InvalidBinaryOperationRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Symplify\TemplatePHPStanCompiler\PHPStan\FileAnalyserProvider;
use Symplify\TemplatePHPStanCompiler\TypeAnalyzer\TemplateVariableTypesResolver;
use Vural\PHPStanBladeRule\Compiler\BladeToPHPCompiler;
use Vural\PHPStanBladeRule\ErrorReporting\Blade\TemplateErrorsFactory;
use Vural\PHPStanBladeRule\NodeAnalyzer\BladeViewMethodsMatcher;
use Vural\PHPStanBladeRule\NodeAnalyzer\LaravelViewFunctionMatcher;
use Vural\PHPStanBladeRule\Rules\BladeRule;
use Vural\PHPStanBladeRule\Rules\ViewRuleHelper;

use function array_merge;

/**
 * @extends RuleTestCase<BladeRule>
 */
class LaravelViewFunctionRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new BladeRule(
            [
                self::getContainer()->getByType(InvalidBinaryOperationRule::class),
                self::getContainer()->getByType(EchoRule::class),
            ],
            self::getContainer()->getByType(BladeViewMethodsMatcher::class),
            self::getContainer()->getByType(LaravelViewFunctionMatcher::class),
            new ViewRuleHelper(
                self::getContainer()->getByType(TemplateVariableTypesResolver::class),
                self::getContainer()->getByType(FileAnalyserProvider::class),
                self::getContainer()->getByType(TemplateErrorsFactory::class),
                self::getContainer()->getByType(BladeToPHPCompiler::class),
            )
        );
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/laravel-view-function.php'], [
            [
                'Binary operation "+" between string and 10 results in an error.',
                9,
            ],
            [
                'Binary operation "+" between string and \'bar\' results in an error.',
                9,
            ],
            [
                'Binary operation "+" between string and 10 results in an error.',
                11,
            ],
        ]);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return array_merge(parent::getAdditionalConfigFiles(), [__DIR__ . '/config/configWithTemplatePaths.neon']);
    }
}

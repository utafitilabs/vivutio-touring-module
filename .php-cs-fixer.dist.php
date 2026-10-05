<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/config', __DIR__.'/migrations'])
    // Symfony dumps a config reference beside a booted application's config.
    // It is generated and gitignored, and the finder does not read .gitignore.
    ->notPath('reference.php')
    ->exclude('var');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        // phpstan reads an inline `/** @var … */`; the default rule would turn
        // it into a plain comment.
        'phpdoc_to_comment' => ['ignored_tags' => ['var']],
        'header_comment' => ['header' => <<<'EOF'
            This file is part of the vivutio touring module.

            (c) Ezekiel Mjema <https://github.com/eemjema>

            For the full copyright and license information, please view the LICENSE
            file that was distributed with this source code.
            EOF],
    ])
    ->setFinder($finder);

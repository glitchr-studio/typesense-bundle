<?php

/*
 * The names the Symfony wiring had before 2.0, when it lived at the root of
 * the package: each is the moved class under its old name (class_alias, not
 * a subclass), declared when something asks for the old name and not
 * before, so nothing of Symfony is loaded where nobody uses it.
 */

spl_autoload_register(static function (string $class): void {
    static $moved = [
        'Typesense\\Bundle\\DependencyInjection\\TypesenseExtension' => 'Typesense\\Bundle\\Bridge\\Symfony\\DependencyInjection\\TypesenseExtension',
        'Typesense\\Bundle\\DependencyInjection\\Configuration' => 'Typesense\\Bundle\\Bridge\\Symfony\\DependencyInjection\\Configuration',
        'Typesense\\Bundle\\DependencyInjection\\Compiler\\CollectionPass' => 'Typesense\\Bundle\\Bridge\\Symfony\\DependencyInjection\\Compiler\\CollectionPass',
        'Typesense\\Bundle\\DependencyInjection\\Compiler\\ConnectionPass' => 'Typesense\\Bundle\\Bridge\\Symfony\\DependencyInjection\\Compiler\\ConnectionPass',
        'Typesense\\Bundle\\DependencyInjection\\Compiler\\FinderPass' => 'Typesense\\Bundle\\Bridge\\Symfony\\DependencyInjection\\Compiler\\FinderPass',
        'Typesense\\Bundle\\DependencyInjection\\Compiler\\MetadataPass' => 'Typesense\\Bundle\\Bridge\\Symfony\\DependencyInjection\\Compiler\\MetadataPass',
        'Typesense\\Bundle\\Command\\ActionCommand' => 'Typesense\\Bundle\\Bridge\\Symfony\\Command\\ActionCommand',
        'Typesense\\Bundle\\Command\\CreateCommand' => 'Typesense\\Bundle\\Bridge\\Symfony\\Command\\CreateCommand',
        'Typesense\\Bundle\\Command\\HealthCommand' => 'Typesense\\Bundle\\Bridge\\Symfony\\Command\\HealthCommand',
        'Typesense\\Bundle\\Command\\ListCommand' => 'Typesense\\Bundle\\Bridge\\Symfony\\Command\\ListCommand',
        'Typesense\\Bundle\\Command\\UpdateCommand' => 'Typesense\\Bundle\\Bridge\\Symfony\\Command\\UpdateCommand',
    ];

    if (isset($moved[$class]) && class_exists($moved[$class])) {
        class_alias($moved[$class], $class);
    }
});

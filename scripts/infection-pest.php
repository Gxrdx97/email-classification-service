<?php

declare(strict_types=1);

// Adapta o runner PHPUnit esperado pelo Infection para Pest, preservando os
// argumentos do Infection e os thresholds configurados em infection.json5.
$arguments = $_SERVER['argv'] ?? [];
$temporaryConfiguration = null;
$jUnitPath = null;
$configurationIndex = array_search('--configuration', $arguments, true);

if (is_int($configurationIndex) && isset($arguments[$configurationIndex + 1])) {
    $configuration = $arguments[$configurationIndex + 1];
    $xml = is_file($configuration) ? file_get_contents($configuration) : false;

    if (is_string($xml)) {
        $document = new DOMDocument;

        if ($document->loadXML($xml, LIBXML_NONET)) {
            foreach ($document->getElementsByTagName('env') as $variable) {
                if (! $variable instanceof DOMElement) {
                    continue;
                }

                $name = $variable->getAttribute('name');
                if ($name === '') {
                    continue;
                }

                $value = $variable->getAttribute('value');
                $force = in_array(strtolower($variable->getAttribute('force')), ['true', '1'], true);

                if ($force || getenv($name) === false) {
                    putenv("{$name}={$value}");
                }

                $value = getenv($name);
                if ($force || ! isset($_ENV[$name])) {
                    $_ENV[$name] = $value;
                }
            }
        }

        // Infection gera um filtro PHPUnit temporário; Pest registra suas
        // próprias suites e não consome esse bloco.
        $sanitized = preg_replace('/\s*<filter>.*?<\/filter>/s', '', $xml);

        if (is_string($sanitized) && $sanitized !== $xml) {
            $temporaryConfiguration = tempnam(sys_get_temp_dir(), 'pest-');

            if ($temporaryConfiguration !== false) {
                file_put_contents($temporaryConfiguration, $sanitized);
                $arguments[$configurationIndex + 1] = $temporaryConfiguration;
            } else {
                $temporaryConfiguration = null;
            }
        }
    }
}

foreach ($arguments as $index => $argument) {
    if (str_starts_with((string) $argument, '--log-junit=')) {
        $jUnitPath = substr((string) $argument, strlen('--log-junit='));

        break;
    }

    if ($argument === '--log-junit' && isset($arguments[$index + 1])) {
        $jUnitPath = (string) $arguments[$index + 1];

        break;
    }
}

$pest = dirname(__DIR__).'/vendor/bin/pest';
$command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($pest);

foreach (array_slice($arguments, 1) as $argument) {
    $command .= ' '.escapeshellarg((string) $argument);
}

// Evals continuam opt-in mesmo quando Infection seleciona testes afetados.
$command .= ' '.escapeshellarg('--testsuite=Unit,Feature,Integration,Contract,BDD,Architecture');

passthru($command, $exitCode);

if ($exitCode === 0 && $jUnitPath !== null && is_file($jUnitPath)) {
    $jUnit = new DOMDocument;
    if (! $jUnit->load($jUnitPath, LIBXML_NONET)) {
        throw new RuntimeException('Infection JUnit report is invalid.');
    }

    // Infection's test IDs include Pest's internal P\\ prefix, absent from the JUnit class field.
    $normalizedTestClasses = [];
    foreach ($jUnit->getElementsByTagName('testcase') as $testCase) {
        if (! $testCase instanceof DOMElement) {
            continue;
        }

        $className = $testCase->getAttribute('class');
        if (str_starts_with($className, 'Tests\\') && ! isset($normalizedTestClasses[$className])) {
            $testCase->setAttribute('class', 'P\\'.$className);
            $normalizedTestClasses[$className] = true;
        }
    }

    if ($jUnit->save($jUnitPath) === false) {
        throw new RuntimeException('Infection JUnit report could not be normalized.');
    }
}

if (is_string($temporaryConfiguration)) {
    unlink($temporaryConfiguration);
}

exit($exitCode);

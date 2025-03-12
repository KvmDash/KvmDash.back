<?php

$baseDir = __DIR__ . '/../src';
$results = [];

function findHardcodedStrings($file) {
    $content = file_get_contents($file);
    $findings = [];
    $lines = explode("\n", $content);

    $patterns = [
        'exceptions' => [
            '/throw new \\\\Exception\([\'"](.+?)[\'"]\)/',
            '/throw new \\\\RuntimeException\([\'"](.+?)[\'"]\)/'
        ],
        'error_logs' => [
            '/error_log\([\'"](.+?)[\'"]\)/'
        ],
        'json_errors' => [
            '/[\'"]error[\'"]\s*=>\s*[\'"](.+?)[\'"]\)/',
            '/[\'"]message[\'"]\s*=>\s*[\'"](.+?)[\'"]\)/',
            '/new JsonResponse\(\[[\'"]error[\'"]\s*=>\s*[\'"](.+?)[\'"]\)/'
        ]
    ];

    foreach ($patterns as $type => $typePatterns) {
        foreach ($typePatterns as $pattern) {
            foreach ($lines as $lineNumber => $line) {
                if (preg_match($pattern, $line, $matches)) {
                    if (!strpos($line, '$this->translator->trans')) {
                        $findings[] = [
                            'type' => $type,
                            'string' => $matches[1],
                            'line' => $lineNumber + 1
                        ];
                    }
                }
            }
        }
    }

    return $findings;
}

// Rekursiv durch alle PHP-Dateien gehen
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $findings = findHardcodedStrings($file->getRealPath());
        if (!empty($findings)) {
            // Relativen Pfad manuell berechnen
            $relativePath = str_replace($baseDir . '/', '', $file->getRealPath());
            $results[$relativePath] = $findings;
        }
    }
}

// Ergebnisse ausgeben
foreach ($results as $file => $findings) {
    echo "\nDatei: $file\n";
    foreach ($findings as $finding) {
        echo sprintf(
            "- Zeile %d - %s: '%s'\n",
            $finding['line'],
            str_pad($finding['type'], 12),
            $finding['string']
        );
    }
}
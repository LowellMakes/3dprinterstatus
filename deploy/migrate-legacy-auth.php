<?php

declare(strict_types=1);

function decodePhpStringLiteral(string $literal): string
{
    if (strlen($literal) < 2) {
        throw new RuntimeException('Malformed PHP string literal.');
    }
    $quote = $literal[0];
    $value = substr($literal, 1, -1);
    if ($quote === "'") {
        return str_replace(["\\\\", "\\'"], ["\\", "'"], $value);
    }
    if ($quote === '"') {
        return stripcslashes($value);
    }

    throw new RuntimeException('Unsupported PHP string literal.');
}

function significantStatements(array $tokens): array
{
    $statements = [];
    $statement = [];
    foreach ($tokens as $token) {
        if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        if ($token === ';') {
            if ($statement !== []) {
                $statements[] = $statement;
                $statement = [];
            }
            continue;
        }
        $statement[] = $token;
    }

    return $statements;
}

function tokenIs(array|string $token, int $type, ?string $value = null): bool
{
    return is_array($token)
        && $token[0] === $type
        && ($value === null || $token[1] === $value);
}

function assignmentStatements(array $statements, string $variableName): array
{
    return array_values(array_filter(
        $statements,
        static fn(array $statement): bool => count($statement) >= 2
            && tokenIs($statement[0], T_VARIABLE, $variableName)
            && $statement[1] === '='
    ));
}

function extractLegacyAuth(string $path): array
{
    if (!is_readable($path)) {
        throw new RuntimeException("Legacy auth file is not readable: {$path}");
    }

    $statements = significantStatements(token_get_all((string)file_get_contents($path)));
    $passwordAssignments = assignmentStatements($statements, '$password');
    if (count($passwordAssignments) !== 1) {
        throw new RuntimeException('Legacy auth must contain exactly one password assignment.');
    }
    $passwordAssignment = $passwordAssignments[0];
    if (count($passwordAssignment) !== 3
        || !tokenIs($passwordAssignment[2], T_CONSTANT_ENCAPSED_STRING)) {
        throw new RuntimeException('Legacy password assignment has unsupported syntax.');
    }

    $allowlistAssignments = assignmentStatements($statements, '$ip_allowlist');
    if ($allowlistAssignments === []) {
        throw new RuntimeException('Legacy auth allowlist assignment was not found.');
    }
    $allowlistAssignment = $allowlistAssignments[0];
    if (count($allowlistAssignment) !== 8
        || !tokenIs($allowlistAssignment[2], T_STRING)
        || strtolower($allowlistAssignment[2][1]) !== 'explode'
        || $allowlistAssignment[3] !== '('
        || !tokenIs($allowlistAssignment[4], T_CONSTANT_ENCAPSED_STRING)
        || $allowlistAssignment[5] !== ','
        || !tokenIs($allowlistAssignment[6], T_CONSTANT_ENCAPSED_STRING)
        || $allowlistAssignment[7] !== ')') {
        throw new RuntimeException('Legacy allowlist assignment has unsupported syntax.');
    }

    $password = decodePhpStringLiteral($passwordAssignment[2][1]);
    $delimiter = decodePhpStringLiteral($allowlistAssignment[4][1]);
    $allowlistText = decodePhpStringLiteral($allowlistAssignment[6][1]);
    if ($password === '' || $delimiter === '') {
        throw new RuntimeException('Legacy auth contains an empty password or allowlist delimiter.');
    }
    $allowlist = array_values(array_filter(
        array_map('trim', explode($delimiter, $allowlistText)),
        static fn(string $entry): bool => $entry !== ''
    ));

    return [
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'ip_allowlist' => $allowlist,
    ];
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    if ($argc !== 2) {
        fwrite(STDERR, "Usage: php migrate-legacy-auth.php <legacy-auth.php>\n");
        exit(2);
    }
    try {
        echo json_encode(extractLegacyAuth($argv[1]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");
        exit(1);
    }
}

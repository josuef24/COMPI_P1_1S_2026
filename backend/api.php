<?php
// API - Endpoint para recibir código Golampi y devolver resultados
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Manejar preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Incluir todos los archivos del intérprete
require_once __DIR__ . '/interpreter/Lexer.php';
require_once __DIR__ . '/interpreter/AST.php';
require_once __DIR__ . '/interpreter/Parser.php';
require_once __DIR__ . '/interpreter/GolampiValue.php';
require_once __DIR__ . '/interpreter/Environment.php';
require_once __DIR__ . '/interpreter/Interpreter.php';

use Interpreter\Lexer;
use Interpreter\Parser;
use Interpreter\Interpreter;
use Interpreter\Environment;

// Obtener código fuente
$input = json_decode(file_get_contents('php://input'), true);
$code = $input['code'] ?? '';

if (empty($code)) {
    echo json_encode(['output' => '', 'errors' => [], 'symbolTable' => []]);
    exit;
}

$allErrors = [];

// Fase 1: Análisis Léxico
$lexer = new Lexer($code);
$tokens = $lexer->tokenize();
$allErrors = array_merge($allErrors, $lexer->getErrors());

// Fase 2: Análisis Sintáctico
$parser = new Parser($tokens);
$ast = $parser->parse();
$allErrors = array_merge($allErrors, $parser->getErrors());

// Fase 3: Análisis Semántico y Ejecución
$interpreter = new Interpreter();
$interpreter->execute($ast);
$allErrors = array_merge($allErrors, $interpreter->getErrors());

// Enumerar errores
$numberedErrors = [];
foreach ($allErrors as $i => $err) {
    $numberedErrors[] = [
        'num' => $i + 1,
        'type' => $err['type'],
        'description' => $err['description'],
        'line' => $err['line'],
        'col' => $err['col'],
    ];
}

// Respuesta
echo json_encode([
    'output' => implode("\n", $interpreter->getOutput()),
    'errors' => $numberedErrors,
    'symbolTable' => Environment::getSymbolReport(),
], JSON_UNESCAPED_UNICODE);

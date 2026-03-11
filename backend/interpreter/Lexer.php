<?php
// Lexer - Analizador Léxico para Golampi
namespace Interpreter;

class Lexer {
    // Código fuente y posición
    private string $source;
    private int $pos = 0;
    private int $line = 1;
    private int $col = 1;
    private array $tokens = [];
    private array $errors = [];

    // Palabras reservadas
    private const KEYWORDS = [
        'var'=>'VAR','const'=>'CONST','func'=>'FUNC','return'=>'RETURN',
        'if'=>'IF','else'=>'ELSE','for'=>'FOR','switch'=>'SWITCH',
        'case'=>'CASE','default'=>'DEFAULT','break'=>'BREAK','continue'=>'CONTINUE',
        'nil'=>'NIL','true'=>'TRUE','false'=>'FALSE',
        'int32'=>'INT32','int'=>'INT32','float32'=>'FLOAT32',
        'bool'=>'BOOL_TYPE','rune'=>'RUNE_TYPE','string'=>'STRING_TYPE',
        'fmt'=>'FMT','Println'=>'PRINTLN',
        'len'=>'LEN','now'=>'NOW','substr'=>'SUBSTR','typeOf'=>'TYPEOF',
    ];

    // Operadores de dos caracteres
    private const TWO_CHAR_OPS = [
        ':=' => 'SHORT_ASSIGN', '+=' => 'PLUS_ASSIGN', '-=' => 'MINUS_ASSIGN',
        '*=' => 'STAR_ASSIGN', '/=' => 'SLASH_ASSIGN', '++' => 'PLUS_PLUS',
        '--' => 'MINUS_MINUS', '==' => 'EQUAL', '!=' => 'NOT_EQUAL',
        '<=' => 'LTE', '>=' => 'GTE', '&&' => 'AND', '||' => 'OR',
    ];

    // Operadores de un carácter
    private const ONE_CHAR_OPS = [
        '+'=>'PLUS', '-'=>'MINUS', '*'=>'STAR', '/'=>'SLASH', '%'=>'PERCENT',
        '='=>'ASSIGN', '<'=>'LT', '>'=>'GT', '!'=>'NOT', '&'=>'AMPERSAND',
        '.'=>'DOT', '('=>'LPAREN', ')'=>'RPAREN', '{'=>'LBRACE', '}'=>'RBRACE',
        '['=>'LBRACKET', ']'=>'RBRACKET', ';'=>'SEMICOLON', ':'=>'COLON', ','=>'COMMA',
    ];

    public function __construct(string $source) {
        $this->source = $source;
    }

    // Tokenizar el código fuente
    public function tokenize(): array {
        while ($this->pos < strlen($this->source)) {
            $ch = $this->source[$this->pos];

            // Espacios en blanco
            if (ctype_space($ch)) {
                $this->skipWhitespace();
                continue;
            }

            // Comentarios
            if ($ch === '/' && $this->pos + 1 < strlen($this->source)) {
                if ($this->source[$this->pos + 1] === '/') {
                    $this->skipLineComment();
                    continue;
                }
                if ($this->source[$this->pos + 1] === '*') {
                    $this->skipBlockComment();
                    continue;
                }
            }

            // Literales de cadena
            if ($ch === '"') {
                $this->readString();
                continue;
            }

            // Literales rune
            if ($ch === '\'') {
                $this->readRune();
                continue;
            }

            // Números
            if (ctype_digit($ch)) {
                $this->readNumber();
                continue;
            }

            // Identificadores y palabras reservadas
            if (ctype_alpha($ch) || $ch === '_') {
                $this->readIdentifier();
                continue;
            }

            // Operadores de dos caracteres
            if ($this->pos + 1 < strlen($this->source)) {
                $twoChar = $ch . $this->source[$this->pos + 1];
                if (isset(self::TWO_CHAR_OPS[$twoChar])) {
                    $this->tokens[] = new Token(self::TWO_CHAR_OPS[$twoChar], $twoChar, $this->line, $this->col);
                    $this->advance();
                    $this->advance();
                    continue;
                }
            }

            // Operadores de un carácter
            if (isset(self::ONE_CHAR_OPS[$ch])) {
                $this->tokens[] = new Token(self::ONE_CHAR_OPS[$ch], $ch, $this->line, $this->col);
                $this->advance();
                continue;
            }

            // Carácter no reconocido
            $this->errors[] = [
                'type' => 'Léxico',
                'description' => "Símbolo no reconocido: $ch",
                'line' => $this->line,
                'col' => $this->col
            ];
            $this->advance();
        }

        $this->tokens[] = new Token('EOF', '', $this->line, $this->col);
        return $this->tokens;
    }

    // Obtener errores léxicos
    public function getErrors(): array {
        return $this->errors;
    }

    // Avanzar un carácter
    private function advance(): void {
        if ($this->pos < strlen($this->source)) {
            if ($this->source[$this->pos] === "\n") {
                $this->line++;
                $this->col = 1;
            } else {
                $this->col++;
            }
            $this->pos++;
        }
    }

    // Carácter actual
    private function current(): string {
        return $this->pos < strlen($this->source) ? $this->source[$this->pos] : '';
    }

    // Peek siguiente carácter
    private function peek(): string {
        return ($this->pos + 1) < strlen($this->source) ? $this->source[$this->pos + 1] : '';
    }

    // Saltar espacios en blanco
    private function skipWhitespace(): void {
        while ($this->pos < strlen($this->source) && ctype_space($this->source[$this->pos])) {
            $this->advance();
        }
    }

    // Saltar comentario de línea
    private function skipLineComment(): void {
        while ($this->pos < strlen($this->source) && $this->source[$this->pos] !== "\n") {
            $this->advance();
        }
    }

    // Saltar comentario de bloque
    private function skipBlockComment(): void {
        $startLine = $this->line;
        $startCol = $this->col;
        $this->advance(); // /
        $this->advance(); // *
        while ($this->pos < strlen($this->source)) {
            if ($this->source[$this->pos] === '*' && $this->peek() === '/') {
                $this->advance(); // *
                $this->advance(); // /
                return;
            }
            $this->advance();
        }
        $this->errors[] = [
            'type' => 'Léxico',
            'description' => 'Comentario de bloque no cerrado',
            'line' => $startLine,
            'col' => $startCol
        ];
    }

    // Leer literal de cadena
    private function readString(): void {
        $startLine = $this->line;
        $startCol = $this->col;
        $value = '';
        $this->advance(); // "
        while ($this->pos < strlen($this->source) && $this->source[$this->pos] !== '"') {
            if ($this->source[$this->pos] === '\\') {
                $this->advance();
                if ($this->pos < strlen($this->source)) {
                    $esc = $this->source[$this->pos];
                    switch ($esc) {
                        case 'n': $value .= "\n"; break;
                        case 't': $value .= "\t"; break;
                        case 'r': $value .= "\r"; break;
                        case '\\': $value .= "\\"; break;
                        case '"': $value .= '"'; break;
                        default: $value .= $esc;
                    }
                }
            } else {
                $value .= $this->source[$this->pos];
            }
            $this->advance();
        }
        if ($this->pos < strlen($this->source)) {
            $this->advance(); // "
        }
        $this->tokens[] = new Token('STRING_LIT', $value, $startLine, $startCol);
    }

    // Leer literal rune
    private function readRune(): void {
        $startLine = $this->line;
        $startCol = $this->col;
        $this->advance(); // '
        $value = '';
        if ($this->pos < strlen($this->source) && $this->source[$this->pos] === '\\') {
            $this->advance();
            if ($this->pos < strlen($this->source)) {
                $esc = $this->source[$this->pos];
                switch ($esc) {
                    case 'n': $value = "\n"; break;
                    case 't': $value = "\t"; break;
                    case 'r': $value = "\r"; break;
                    case '\\': $value = "\\"; break;
                    case '\'': $value = "'"; break;
                    case 'u':
                        $this->advance();
                        $hex = '';
                        for ($i = 0; $i < 4 && $this->pos < strlen($this->source); $i++) {
                            $hex .= $this->source[$this->pos];
                            $this->advance();
                        }
                        $value = mb_chr(hexdec($hex));
                        if ($this->pos < strlen($this->source) && $this->source[$this->pos] === '\'') {
                            $this->advance();
                        }
                        $this->tokens[] = new Token('RUNE_LIT', $value, $startLine, $startCol);
                        return;
                    default: $value = $esc;
                }
                $this->advance();
            }
        } else if ($this->pos < strlen($this->source)) {
            $value = $this->source[$this->pos];
            $this->advance();
        }
        if ($this->pos < strlen($this->source) && $this->source[$this->pos] === '\'') {
            $this->advance();
        }
        $this->tokens[] = new Token('RUNE_LIT', $value, $startLine, $startCol);
    }

    // Leer número
    private function readNumber(): void {
        $startLine = $this->line;
        $startCol = $this->col;
        $num = '';
        $isFloat = false;
        while ($this->pos < strlen($this->source) && ctype_digit($this->source[$this->pos])) {
            $num .= $this->source[$this->pos];
            $this->advance();
        }
        if ($this->pos < strlen($this->source) && $this->source[$this->pos] === '.' && ctype_digit($this->peek())) {
            $isFloat = true;
            $num .= '.';
            $this->advance();
            while ($this->pos < strlen($this->source) && ctype_digit($this->source[$this->pos])) {
                $num .= $this->source[$this->pos];
                $this->advance();
            }
        }
        $type = $isFloat ? 'FLOAT_LIT' : 'INT_LIT';
        $this->tokens[] = new Token($type, $num, $startLine, $startCol);
    }

    // Leer identificador o palabra reservada
    private function readIdentifier(): void {
        $startLine = $this->line;
        $startCol = $this->col;
        $id = '';
        while ($this->pos < strlen($this->source) && (ctype_alnum($this->source[$this->pos]) || $this->source[$this->pos] === '_')) {
            $id .= $this->source[$this->pos];
            $this->advance();
        }
        $type = self::KEYWORDS[$id] ?? 'IDENTIFIER';
        $this->tokens[] = new Token($type, $id, $startLine, $startCol);
    }
}

// Token
class Token {
    public string $type;
    public string $value;
    public int $line;
    public int $col;

    public function __construct(string $type, string $value, int $line, int $col) {
        $this->type = $type;
        $this->value = $value;
        $this->line = $line;
        $this->col = $col;
    }
}

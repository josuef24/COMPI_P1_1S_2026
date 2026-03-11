<?php
// Parser - Analizador Sintáctico (Recursive Descent) para Golampi
namespace Interpreter;

class Parser {
    private array $tokens;
    private int $pos = 0;
    private array $errors = [];

    public function __construct(array $tokens) {
        $this->tokens = $tokens;
    }

    // Obtener errores sintácticos
    public function getErrors(): array {
        return $this->errors;
    }

    // Token actual
    private function current(): Token {
        return $this->tokens[$this->pos] ?? new Token('EOF', '', 0, 0);
    }

    // Peek token
    private function peek(int $offset = 1): Token {
        return $this->tokens[$this->pos + $offset] ?? new Token('EOF', '', 0, 0);
    }

    // Avanzar y retornar token anterior
    private function advance(): Token {
        $tok = $this->current();
        if ($this->pos < count($this->tokens) - 1) $this->pos++;
        return $tok;
    }

    // Esperar un token específico
    private function expect(string $type): Token {
        if ($this->current()->type === $type) {
            return $this->advance();
        }
        $this->errors[] = [
            'type' => 'Sintáctico',
            'description' => "Se esperaba '$type', se encontró '{$this->current()->type}' ({$this->current()->value})",
            'line' => $this->current()->line,
            'col' => $this->current()->col
        ];
        return $this->current();
    }

    // Verificar si el token actual es del tipo dado
    private function match(string $type): bool {
        return $this->current()->type === $type;
    }

    // Verificar y avanzar si coincide
    private function matchAndAdvance(string $type): ?Token {
        if ($this->current()->type === $type) {
            return $this->advance();
        }
        return null;
    }

    // Parsear programa completo
    public function parse(): ProgramNode {
        $program = new ProgramNode($this->current()->line, $this->current()->col);
        while (!$this->match('EOF')) {
            try {
                if ($this->match('FUNC')) {
                    $program->declarations[] = $this->parseFuncDecl();
                } elseif ($this->match('VAR')) {
                    $program->declarations[] = $this->parseVarDecl();
                } elseif ($this->match('CONST')) {
                    $program->declarations[] = $this->parseConstDecl();
                } else {
                    $this->errors[] = [
                        'type' => 'Sintáctico',
                        'description' => "Declaración no esperada: '{$this->current()->value}'",
                        'line' => $this->current()->line,
                        'col' => $this->current()->col
                    ];
                    $this->advance();
                }
            } catch (\Exception $e) {
                $this->advance();
            }
        }
        return $program;
    }

    // Parsear declaración de función
    private function parseFuncDecl(): FuncDeclNode {
        $node = new FuncDeclNode($this->current()->line, $this->current()->col);
        $this->expect('FUNC');
        $nameTok = $this->expect('IDENTIFIER');
        $node->name = $nameTok->value;
        $this->expect('LPAREN');
        $node->params = [];
        if (!$this->match('RPAREN')) {
            $node->params = $this->parseParamList();
        }
        $this->expect('RPAREN');
        // Tipo de retorno
        $node->returnTypes = [];
        if ($this->match('LPAREN')) {
            // Múltiples retornos: (type, type, ...)
            $this->advance();
            $node->returnTypes[] = $this->parseTypeString();
            while ($this->matchAndAdvance('COMMA')) {
                $node->returnTypes[] = $this->parseTypeString();
            }
            $this->expect('RPAREN');
        } elseif ($this->isTypeToken()) {
            // Un solo retorno
            $node->returnTypes[] = $this->parseTypeString();
        }
        $node->body = $this->parseBlock();
        return $node;
    }

    // Parsear lista de parámetros
    private function parseParamList(): array {
        $params = [];
        $params[] = $this->parseParam();
        while ($this->matchAndAdvance('COMMA')) {
            $params[] = $this->parseParam();
        }
        return $params;
    }

    // Parsear parámetro individual
    private function parseParam(): array {
        $name = $this->expect('IDENTIFIER')->value;
        $isPointer = false;
        if ($this->matchAndAdvance('STAR')) {
            $isPointer = true;
        }
        $type = $this->parseTypeString();
        return ['name' => $name, 'type' => $type, 'isPointer' => $isPointer];
    }

    // Verificar si el token actual es un tipo
    private function isTypeToken(): bool {
        return in_array($this->current()->type, ['INT32','FLOAT32','BOOL_TYPE','RUNE_TYPE','STRING_TYPE','LBRACKET','STAR']);
    }

    // Parsear tipo como string
    private function parseTypeString(): string {
        if ($this->matchAndAdvance('STAR')) {
            return '*' . $this->parseTypeString();
        }
        if ($this->match('LBRACKET')) {
            $dims = '';
            while ($this->match('LBRACKET')) {
                $this->advance();
                $dims .= '[' . $this->current()->value . ']';
                $this->advance(); // size expression (simplified to literal)
                $this->expect('RBRACKET');
            }
            $base = $this->parsePrimitiveType();
            return $dims . $base;
        }
        return $this->parsePrimitiveType();
    }

    // Parsear tipo primitivo
    private function parsePrimitiveType(): string {
        $tok = $this->current();
        if (in_array($tok->type, ['INT32','FLOAT32','BOOL_TYPE','RUNE_TYPE','STRING_TYPE'])) {
            $this->advance();
            // Normalizar "int" a "int32"
            if ($tok->value === 'int') return 'int32';
            return $tok->value;
        }
        $this->errors[] = [
            'type' => 'Sintáctico',
            'description' => "Se esperaba un tipo, se encontró '{$tok->value}'",
            'line' => $tok->line,
            'col' => $tok->col
        ];
        return 'int32';
    }

    // Parsear bloque
    private function parseBlock(): BlockNode {
        $node = new BlockNode($this->current()->line, $this->current()->col);
        $this->expect('LBRACE');
        while (!$this->match('RBRACE') && !$this->match('EOF')) {
            try {
                $stmt = $this->parseStatement();
                if ($stmt !== null) $node->statements[] = $stmt;
            } catch (\Exception $e) {
                $this->advance();
            }
        }
        $this->expect('RBRACE');
        return $node;
    }

    // Parsear sentencia
    private function parseStatement(): ?ASTNode {
        switch ($this->current()->type) {
            case 'VAR': return $this->parseVarDecl();
            case 'CONST': return $this->parseConstDecl();
            case 'IF': return $this->parseIf();
            case 'SWITCH': return $this->parseSwitch();
            case 'FOR': return $this->parseFor();
            case 'RETURN': return $this->parseReturn();
            case 'BREAK':
                $node = new BreakNode($this->current()->line, $this->current()->col);
                $this->advance();
                return $node;
            case 'CONTINUE':
                $node = new ContinueNode($this->current()->line, $this->current()->col);
                $this->advance();
                return $node;
            case 'FMT': return $this->parsePrintlnStmt();
            case 'IDENTIFIER':
                return $this->parseIdentifierStmt();
            case 'STAR':
            case 'LPAREN':
            default:
                // Dereference assignment (*ptr = val) o expresión general
                $expr = $this->parseExpression();
                if ($this->isAssignOp()) {
                    $op = $this->advance()->value;
                    $value = $this->parseExpression();
                    $node = new AssignNode($expr->line, $expr->col);
                    $node->target = $expr;
                    $node->op = $op;
                    $node->value = $value;
                    return $node;
                }
                $node = new ExprStmtNode($expr->line, $expr->col);
                $node->expr = $expr;
                return $node;
        }
    }

    // Parsear sentencia que empieza con identificador
    private function parseIdentifierStmt(): ASTNode {
        // Puede ser: asignación, declaración corta, llamada a función, inc/dec
        $startPos = $this->pos;

        // Revisar si es declaración corta (id, id := expr, expr)
        if ($this->isShortVarDecl()) {
            return $this->parseShortVarDecl();
        }

        // Revisar inc/dec
        if ($this->peek()->type === 'PLUS_PLUS' || $this->peek()->type === 'MINUS_MINUS') {
            $name = $this->advance()->value;
            $op = $this->advance()->value;
            $node = new IncDecNode($this->current()->line, $this->current()->col);
            $node->name = $name;
            $node->op = $op;
            return $node;
        }

        // Parsear como expresión primero (puede ser llamada a función o acceso a arreglo)
        $expr = $this->parseExpression();

        // Verificar si es asignación
        if ($this->isAssignOp()) {
            $op = $this->advance()->value;
            $value = $this->parseExpression();
            $node = new AssignNode($expr->line, $expr->col);
            $node->target = $expr;
            $node->op = $op;
            $node->value = $value;
            return $node;
        }

        // Si es solo expresión
        $node = new ExprStmtNode($expr->line, $expr->col);
        $node->expr = $expr;
        return $node;
    }

    // Verificar si es declaración corta
    private function isShortVarDecl(): bool {
        $saved = $this->pos;
        // Consumir IDs separados por coma
        if ($this->current()->type !== 'IDENTIFIER') { return false; }
        $this->pos++;
        while ($this->current()->type === 'COMMA') {
            $this->pos++;
            if ($this->current()->type !== 'IDENTIFIER') { $this->pos = $saved; return false; }
            $this->pos++;
        }
        $result = $this->current()->type === 'SHORT_ASSIGN';
        $this->pos = $saved;
        return $result;
    }

    // Verificar si el token actual es operador de asignación
    private function isAssignOp(): bool {
        return in_array($this->current()->type, ['ASSIGN','PLUS_ASSIGN','MINUS_ASSIGN','STAR_ASSIGN','SLASH_ASSIGN']);
    }

    // Parsear declaración de variable
    private function parseVarDecl(): ASTNode {
        $line = $this->current()->line;
        $col = $this->current()->col;
        $this->expect('VAR');
        $node = new VarDeclNode($line, $col);

        // Primer identificador
        $firstName = $this->expect('IDENTIFIER')->value;
        $node->names = [$firstName];

        // Verificar si es arreglo: var id [dim]type
        if ($this->match('LBRACKET')) {
            $node->arrayDims = [];
            while ($this->match('LBRACKET')) {
                $this->advance();
                $dimExpr = $this->parseExpression();
                $node->arrayDims[] = $dimExpr;
                $this->expect('RBRACKET');
            }
            $node->type = $this->parsePrimitiveType();

            // Inicialización opcional: = [dim]type{values}
            if ($this->matchAndAdvance('ASSIGN')) {
                $node->arrayInit = $this->parseArrayLiteral();
            }
            return $node;
        }

        // Múltiples nombres: var a, b type
        while ($this->matchAndAdvance('COMMA')) {
            $node->names[] = $this->expect('IDENTIFIER')->value;
        }

        // Tipo
        $node->type = $this->parseTypeString();

        // Inicialización opcional
        if ($this->matchAndAdvance('ASSIGN')) {
            $node->values = $this->parseExpressionList();
        }
        return $node;
    }

    // Parsear declaración corta
    private function parseShortVarDecl(): ShortVarDeclNode {
        $node = new ShortVarDeclNode($this->current()->line, $this->current()->col);
        $node->names = [$this->expect('IDENTIFIER')->value];
        while ($this->matchAndAdvance('COMMA')) {
            $node->names[] = $this->expect('IDENTIFIER')->value;
        }
        $this->expect('SHORT_ASSIGN');
        $node->values = $this->parseExpressionList();
        return $node;
    }

    // Parsear declaración de constante
    private function parseConstDecl(): ConstDeclNode {
        $node = new ConstDeclNode($this->current()->line, $this->current()->col);
        $this->expect('CONST');
        $node->name = $this->expect('IDENTIFIER')->value;
        $node->type = $this->parseTypeString();
        $this->expect('ASSIGN');
        $node->value = $this->parseExpression();
        return $node;
    }

    // Parsear if
    private function parseIf(): IfNode {
        $node = new IfNode($this->current()->line, $this->current()->col);
        $this->expect('IF');
        $node->condition = $this->parseExpression();
        $node->body = $this->parseBlock();
        if ($this->matchAndAdvance('ELSE')) {
            if ($this->match('IF')) {
                $node->elseBody = $this->parseIf();
            } else {
                $node->elseBody = $this->parseBlock();
            }
        }
        return $node;
    }

    // Parsear switch
    private function parseSwitch(): SwitchNode {
        $node = new SwitchNode($this->current()->line, $this->current()->col);
        $this->expect('SWITCH');
        $node->expr = $this->parseExpression();
        $this->expect('LBRACE');
        while ($this->match('CASE')) {
            $node->cases[] = $this->parseCase();
        }
        if ($this->match('DEFAULT')) {
            $node->default = $this->parseDefault();
        }
        $this->expect('RBRACE');
        return $node;
    }

    // Parsear case
    private function parseCase(): CaseNode {
        $node = new CaseNode($this->current()->line, $this->current()->col);
        $this->expect('CASE');
        $node->values = $this->parseExpressionList();
        $this->expect('COLON');
        $node->body = [];
        while (!$this->match('CASE') && !$this->match('DEFAULT') && !$this->match('RBRACE') && !$this->match('EOF')) {
            $stmt = $this->parseStatement();
            if ($stmt !== null) $node->body[] = $stmt;
        }
        return $node;
    }

    // Parsear default
    private function parseDefault(): CaseNode {
        $node = new CaseNode($this->current()->line, $this->current()->col);
        $this->expect('DEFAULT');
        $this->expect('COLON');
        $node->values = null;
        $node->body = [];
        while (!$this->match('RBRACE') && !$this->match('EOF')) {
            $stmt = $this->parseStatement();
            if ($stmt !== null) $node->body[] = $stmt;
        }
        return $node;
    }

    // Parsear for
    private function parseFor(): ForNode {
        $node = new ForNode($this->current()->line, $this->current()->col);
        $this->expect('FOR');

        // for { ... } (infinito)
        if ($this->match('LBRACE')) {
            $node->body = $this->parseBlock();
            return $node;
        }

        // Intentar for clásico vs condicional
        $savedPos = $this->pos;

        // Verificar si hay punto y coma (for clásico)
        if ($this->isClassicFor()) {
            // for init; cond; post { ... }
            if (!$this->match('SEMICOLON')) {
                $node->init = $this->parseForInit();
            }
            $this->expect('SEMICOLON');
            if (!$this->match('SEMICOLON')) {
                $node->condition = $this->parseExpression();
            }
            $this->expect('SEMICOLON');
            if (!$this->match('LBRACE')) {
                $node->post = $this->parseForPost();
            }
            $node->body = $this->parseBlock();
        } else {
            // for cond { ... } (condicional)
            $node->condition = $this->parseExpression();
            $node->body = $this->parseBlock();
        }
        return $node;
    }

    // Detectar for clásico buscando ; antes de {
    private function isClassicFor(): bool {
        $saved = $this->pos;
        $depth = 0;
        while ($this->pos < count($this->tokens) - 1) {
            $t = $this->current()->type;
            if ($t === 'LPAREN') $depth++;
            if ($t === 'RPAREN') $depth--;
            if ($t === 'LBRACE' && $depth === 0) { $this->pos = $saved; return false; }
            if ($t === 'SEMICOLON' && $depth === 0) { $this->pos = $saved; return true; }
            $this->pos++;
        }
        $this->pos = $saved;
        return false;
    }

    // Parsear init del for
    private function parseForInit(): ASTNode {
        if ($this->isShortVarDecl()) {
            return $this->parseShortVarDecl();
        }
        $expr = $this->parseExpression();
        if ($this->isAssignOp()) {
            $op = $this->advance()->value;
            $val = $this->parseExpression();
            $node = new AssignNode($expr->line, $expr->col);
            $node->target = $expr;
            $node->op = $op;
            $node->value = $val;
            return $node;
        }
        $node = new ExprStmtNode($expr->line, $expr->col);
        $node->expr = $expr;
        return $node;
    }

    // Parsear post del for
    private function parseForPost(): ASTNode {
        // inc/dec
        if ($this->current()->type === 'IDENTIFIER' &&
            ($this->peek()->type === 'PLUS_PLUS' || $this->peek()->type === 'MINUS_MINUS')) {
            $name = $this->advance()->value;
            $op = $this->advance()->value;
            $node = new IncDecNode($this->current()->line, $this->current()->col);
            $node->name = $name;
            $node->op = $op;
            return $node;
        }
        $expr = $this->parseExpression();
        if ($this->isAssignOp()) {
            $op = $this->advance()->value;
            $val = $this->parseExpression();
            $node = new AssignNode($expr->line, $expr->col);
            $node->target = $expr;
            $node->op = $op;
            $node->value = $val;
            return $node;
        }
        $node = new ExprStmtNode($expr->line, $expr->col);
        $node->expr = $expr;
        return $node;
    }

    // Parsear return
    private function parseReturn(): ReturnNode {
        $node = new ReturnNode($this->current()->line, $this->current()->col);
        $this->expect('RETURN');
        if (!$this->match('RBRACE') && !$this->match('EOF') && !$this->match('CASE') && !$this->match('DEFAULT')) {
            $node->values = $this->parseExpressionList();
        }
        return $node;
    }

    // Parsear fmt.Println como sentencia
    private function parsePrintlnStmt(): ExprStmtNode {
        $expr = $this->parsePrintlnExpr();
        $node = new ExprStmtNode($expr->line, $expr->col);
        $node->expr = $expr;
        return $node;
    }

    // Parsear fmt.Println como expresión
    private function parsePrintlnExpr(): PrintlnNode {
        $node = new PrintlnNode($this->current()->line, $this->current()->col);
        $this->expect('FMT');
        $this->expect('DOT');
        $this->expect('PRINTLN');
        $this->expect('LPAREN');
        if (!$this->match('RPAREN')) {
            $node->args = $this->parseExpressionList();
        }
        $this->expect('RPAREN');
        return $node;
    }

    // Parsear lista de expresiones
    private function parseExpressionList(): array {
        $exprs = [$this->parseExpression()];
        while ($this->matchAndAdvance('COMMA')) {
            $exprs[] = $this->parseExpression();
        }
        return $exprs;
    }

    // --- Expresiones con precedencia ---

    // Parsear expresión (nivel más bajo = OR)
    private function parseExpression(): ASTNode {
        return $this->parseOr();
    }

    // OR
    private function parseOr(): ASTNode {
        $left = $this->parseAnd();
        while ($this->match('OR')) {
            $op = $this->advance()->value;
            $right = $this->parseAnd();
            $node = new BinaryOpNode($left->line, $left->col);
            $node->left = $left;
            $node->op = $op;
            $node->right = $right;
            $left = $node;
        }
        return $left;
    }

    // AND
    private function parseAnd(): ASTNode {
        $left = $this->parseEquality();
        while ($this->match('AND')) {
            $op = $this->advance()->value;
            $right = $this->parseEquality();
            $node = new BinaryOpNode($left->line, $left->col);
            $node->left = $left;
            $node->op = $op;
            $node->right = $right;
            $left = $node;
        }
        return $left;
    }

    // Igualdad
    private function parseEquality(): ASTNode {
        $left = $this->parseRelational();
        while ($this->match('EQUAL') || $this->match('NOT_EQUAL')) {
            $op = $this->advance()->value;
            $right = $this->parseRelational();
            $node = new BinaryOpNode($left->line, $left->col);
            $node->left = $left;
            $node->op = $op;
            $node->right = $right;
            $left = $node;
        }
        return $left;
    }

    // Relacional
    private function parseRelational(): ASTNode {
        $left = $this->parseAddSub();
        while ($this->match('LT') || $this->match('GT') || $this->match('LTE') || $this->match('GTE')) {
            $op = $this->advance()->value;
            $right = $this->parseAddSub();
            $node = new BinaryOpNode($left->line, $left->col);
            $node->left = $left;
            $node->op = $op;
            $node->right = $right;
            $left = $node;
        }
        return $left;
    }

    // Suma y resta
    private function parseAddSub(): ASTNode {
        $left = $this->parseMulDiv();
        while ($this->match('PLUS') || $this->match('MINUS')) {
            $op = $this->advance()->value;
            $right = $this->parseMulDiv();
            $node = new BinaryOpNode($left->line, $left->col);
            $node->left = $left;
            $node->op = $op;
            $node->right = $right;
            $left = $node;
        }
        return $left;
    }

    // Multiplicación, división, módulo
    private function parseMulDiv(): ASTNode {
        $left = $this->parseUnary();
        while ($this->match('STAR') || $this->match('SLASH') || $this->match('PERCENT')) {
            $op = $this->advance()->value;
            $right = $this->parseUnary();
            $node = new BinaryOpNode($left->line, $left->col);
            $node->left = $left;
            $node->op = $op;
            $node->right = $right;
            $left = $node;
        }
        return $left;
    }

    // Unarios
    private function parseUnary(): ASTNode {
        // Negación lógica
        if ($this->match('NOT')) {
            $tok = $this->advance();
            $operand = $this->parseUnary();
            $node = new UnaryOpNode($tok->line, $tok->col);
            $node->op = '!';
            $node->operand = $operand;
            return $node;
        }
        // Negación aritmética
        if ($this->match('MINUS')) {
            $tok = $this->advance();
            $operand = $this->parseUnary();
            $node = new UnaryOpNode($tok->line, $tok->col);
            $node->op = '-';
            $node->operand = $operand;
            return $node;
        }
        // Referencia &
        if ($this->match('AMPERSAND')) {
            $tok = $this->advance();
            $name = $this->expect('IDENTIFIER')->value;
            $node = new ReferenceNode($tok->line, $tok->col);
            $node->name = $name;
            return $node;
        }
        // Desreferencia *
        if ($this->match('STAR')) {
            $tok = $this->advance();
            $operand = $this->parseUnary();
            $node = new DereferenceNode($tok->line, $tok->col);
            $node->operand = $operand;
            return $node;
        }
        return $this->parsePostfix();
    }

    // Postfijos (acceso a arreglo)
    private function parsePostfix(): ASTNode {
        $expr = $this->parsePrimary();
        while ($this->match('LBRACKET')) {
            $this->advance();
            $index = $this->parseExpression();
            $this->expect('RBRACKET');
            $node = new ArrayAccessNode($expr->line, $expr->col);
            $node->array = $expr;
            $node->index = $index;
            $expr = $node;
        }
        return $expr;
    }

    // Primarios
    private function parsePrimary(): ASTNode {
        $tok = $this->current();

        // Paréntesis
        if ($this->match('LPAREN')) {
            $this->advance();
            $expr = $this->parseExpression();
            $this->expect('RPAREN');
            return $expr;
        }

        // Literales
        if ($this->match('INT_LIT')) {
            $this->advance();
            return new IntLitNode((int)$tok->value, $tok->line, $tok->col);
        }
        if ($this->match('FLOAT_LIT')) {
            $this->advance();
            return new FloatLitNode((float)$tok->value, $tok->line, $tok->col);
        }
        if ($this->match('STRING_LIT')) {
            $this->advance();
            return new StringLitNode($tok->value, $tok->line, $tok->col);
        }
        if ($this->match('RUNE_LIT')) {
            $this->advance();
            return new RuneLitNode($tok->value, $tok->line, $tok->col);
        }
        if ($this->match('TRUE')) {
            $this->advance();
            return new BoolLitNode(true, $tok->line, $tok->col);
        }
        if ($this->match('FALSE')) {
            $this->advance();
            return new BoolLitNode(false, $tok->line, $tok->col);
        }
        if ($this->match('NIL')) {
            $this->advance();
            return new NilLitNode($tok->line, $tok->col);
        }

        // fmt.Println
        if ($this->match('FMT')) {
            return $this->parsePrintlnExpr();
        }

        // Funciones embebidas
        if ($this->match('LEN')) {
            return $this->parseLenExpr();
        }
        if ($this->match('NOW')) {
            return $this->parseNowExpr();
        }
        if ($this->match('SUBSTR')) {
            return $this->parseSubstrExpr();
        }
        if ($this->match('TYPEOF')) {
            return $this->parseTypeOfExpr();
        }

        // Literal de arreglo: [size]type{...}
        if ($this->match('LBRACKET') && $this->isArrayLiteral()) {
            return $this->parseArrayLiteral();
        }

        // Identificador (variable o llamada a función)
        if ($this->match('IDENTIFIER')) {
            $nameTok = $this->advance();
            // Llamada a función
            if ($this->match('LPAREN')) {
                $this->advance();
                $node = new FuncCallNode($nameTok->line, $nameTok->col);
                $node->name = $nameTok->value;
                if (!$this->match('RPAREN')) {
                    $node->args = $this->parseExpressionList();
                }
                $this->expect('RPAREN');
                return $node;
            }
            return new IdentifierNode($nameTok->value, $nameTok->line, $nameTok->col);
        }

        $this->errors[] = [
            'type' => 'Sintáctico',
            'description' => "Expresión no esperada: '{$tok->value}'",
            'line' => $tok->line,
            'col' => $tok->col
        ];
        $this->advance();
        return new NilLitNode($tok->line, $tok->col);
    }

    // Verificar si es literal de arreglo
    private function isArrayLiteral(): bool {
        $saved = $this->pos;
        $depth = 1;
        $this->pos++; // skip [
        while ($this->pos < count($this->tokens) - 1 && $depth > 0) {
            if ($this->current()->type === 'LBRACKET') $depth++;
            if ($this->current()->type === 'RBRACKET') $depth--;
            $this->pos++;
        }
        // Después del ] debe venir un tipo
        $isArr = $this->isTypeToken();
        $this->pos = $saved;
        return $isArr;
    }

    // Parsear literal de arreglo
    private function parseArrayLiteral(): ArrayLitNode {
        $node = new ArrayLitNode($this->current()->line, $this->current()->col);
        // Parsear dimensiones [n]
        while ($this->match('LBRACKET')) {
            $this->advance();
            $node->dims[] = $this->parseExpression();
            $this->expect('RBRACKET');
        }
        $node->elementType = $this->parsePrimitiveType();
        $this->expect('LBRACE');
        if (!$this->match('RBRACE')) {
            // Verificar si es inicialización anidada {{},...}
            if ($this->match('LBRACE')) {
                while ($this->match('LBRACE')) {
                    $this->advance();
                    $subValues = [];
                    if (!$this->match('RBRACE')) {
                        $subValues = $this->parseExpressionList();
                    }
                    $this->expect('RBRACE');
                    $subNode = new ArrayLitNode($node->line, $node->col);
                    $subNode->values = $subValues;
                    $subNode->elementType = $node->elementType;
                    $node->values[] = $subNode;
                    $this->matchAndAdvance('COMMA');
                }
            } else {
                $node->values = $this->parseExpressionList();
            }
        }
        $this->expect('RBRACE');
        return $node;
    }

    // Parsear len()
    private function parseLenExpr(): LenNode {
        $node = new LenNode($this->current()->line, $this->current()->col);
        $this->expect('LEN');
        $this->expect('LPAREN');
        $node->arg = $this->parseExpression();
        $this->expect('RPAREN');
        return $node;
    }

    // Parsear now()
    private function parseNowExpr(): NowNode {
        $node = new NowNode($this->current()->line, $this->current()->col);
        $this->expect('NOW');
        $this->expect('LPAREN');
        $this->expect('RPAREN');
        return $node;
    }

    // Parsear substr()
    private function parseSubstrExpr(): SubstrNode {
        $node = new SubstrNode($this->current()->line, $this->current()->col);
        $this->expect('SUBSTR');
        $this->expect('LPAREN');
        $node->str = $this->parseExpression();
        $this->expect('COMMA');
        $node->start = $this->parseExpression();
        $this->expect('COMMA');
        $node->length = $this->parseExpression();
        $this->expect('RPAREN');
        return $node;
    }

    // Parsear typeOf()
    private function parseTypeOfExpr(): TypeOfNode {
        $node = new TypeOfNode($this->current()->line, $this->current()->col);
        $this->expect('TYPEOF');
        $this->expect('LPAREN');
        $node->arg = $this->parseExpression();
        $this->expect('RPAREN');
        return $node;
    }
}

<?php
// Interpreter - Motor de ejecución del AST de Golampi
namespace Interpreter;

// Señales de control de flujo
class BreakSignal extends \Exception {}
class ContinueSignal extends \Exception {}
class ReturnSignal extends \Exception {
    public array $values;
    public function __construct(array $values) {
        parent::__construct('return');
        $this->values = $values;
    }
}

class Interpreter {
    // Entorno global
    private Environment $globalEnv;
    // Funciones declaradas (hoisting)
    private array $functions = [];
    // Salida de consola
    private array $output = [];
    // Errores semánticos
    private array $errors = [];

    public function __construct() {
        $this->globalEnv = new Environment(null, 'global');
    }

    // Obtener salida
    public function getOutput(): array { return $this->output; }
    // Obtener errores semánticos
    public function getErrors(): array { return $this->errors; }

    // Error semántico
    private function semanticError(string $msg, int $line, int $col): void {
        $this->errors[] = [
            'type' => 'Semántico',
            'description' => $msg,
            'line' => $line,
            'col' => $col,
        ];
    }

    // Ejecutar programa
    public function execute(ProgramNode $program): void {
        Environment::clearSymbolReport();

        // Primer pase: registrar funciones (hoisting)
        foreach ($program->declarations as $decl) {
            if ($decl instanceof FuncDeclNode) {
                $this->functions[$decl->name] = $decl;
                $this->globalEnv->declare($decl->name,
                    new GolampiValue('función', $decl->name), $decl->line, $decl->col);
            }
        }

        // Segundo pase: declaraciones globales de variables/constantes
        foreach ($program->declarations as $decl) {
            if ($decl instanceof VarDeclNode) {
                $this->execVarDecl($decl, $this->globalEnv);
            } elseif ($decl instanceof ConstDeclNode) {
                $this->execConstDecl($decl, $this->globalEnv);
            }
        }

        // Buscar y ejecutar main
        if (!isset($this->functions['main'])) {
            $this->semanticError("No se encontró la función 'main'", 0, 0);
            return;
        }
        $mainFunc = $this->functions['main'];
        if (!empty($mainFunc->params)) {
            $this->semanticError("La función 'main' no puede recibir parámetros", $mainFunc->line, $mainFunc->col);
        }
        if (!empty($mainFunc->returnTypes)) {
            $this->semanticError("La función 'main' no puede retornar valores", $mainFunc->line, $mainFunc->col);
        }
        $mainEnv = $this->globalEnv->createChild('main');
        try {
            $this->execBlock($mainFunc->body, $mainEnv);
        } catch (ReturnSignal $r) {
            // main terminó con return
        }
    }

    // Ejecutar bloque
    private function execBlock(BlockNode $block, Environment $env): void {
        foreach ($block->statements as $stmt) {
            $this->execStatement($stmt, $env);
        }
    }

    // Ejecutar sentencia
    private function execStatement(ASTNode $stmt, Environment $env): void {
        if ($stmt instanceof VarDeclNode) { $this->execVarDecl($stmt, $env); }
        elseif ($stmt instanceof ShortVarDeclNode) { $this->execShortVarDecl($stmt, $env); }
        elseif ($stmt instanceof ConstDeclNode) { $this->execConstDecl($stmt, $env); }
        elseif ($stmt instanceof AssignNode) { $this->execAssign($stmt, $env); }
        elseif ($stmt instanceof IfNode) { $this->execIf($stmt, $env); }
        elseif ($stmt instanceof SwitchNode) { $this->execSwitch($stmt, $env); }
        elseif ($stmt instanceof ForNode) { $this->execFor($stmt, $env); }
        elseif ($stmt instanceof ReturnNode) { $this->execReturn($stmt, $env); }
        elseif ($stmt instanceof BreakNode) { throw new BreakSignal(); }
        elseif ($stmt instanceof ContinueNode) { throw new ContinueSignal(); }
        elseif ($stmt instanceof IncDecNode) { $this->execIncDec($stmt, $env); }
        elseif ($stmt instanceof ExprStmtNode) { $this->evalExpr($stmt->expr, $env); }
    }

    // Ejecutar declaración de variable
    private function execVarDecl(VarDeclNode $decl, Environment $env): void {
        $names = $decl->names;
        $type = $decl->type;

        // Arreglo
        if ($decl->arrayDims !== null) {
            $name = $names[0];
            $dims = [];
            foreach ($decl->arrayDims as $dimExpr) {
                $dimVal = $this->evalExpr($dimExpr, $env);
                $dims[] = $dimVal->toInt();
            }
            if ($decl->arrayInit !== null) {
                $val = $this->evalExpr($decl->arrayInit, $env);
                $arrVal = new GolampiValue('array', $val->value, $dims);
                $arrVal->type = 'arreglo';
            } else {
                $arr = $this->createDefaultArray($dims, $type);
                $arrVal = new GolampiValue('arreglo', $arr, $dims);
            }
            if (!$env->declare($name, $arrVal, $decl->line, $decl->col)) {
                $this->semanticError("Identificador '$name' ya ha sido declarado", $decl->line, $decl->col);
            }
            return;
        }

        // Variables simples/múltiples
        if ($decl->values !== null) {
            if (count($names) !== count($decl->values)) {
                $this->semanticError("Número de variables y valores no coincide", $decl->line, $decl->col);
                return;
            }
            foreach ($names as $i => $name) {
                $val = $this->evalExpr($decl->values[$i], $env);
                $typed = $this->castToType($val, $type, $decl->line, $decl->col);
                if (!$env->declare($name, $typed, $decl->line, $decl->col)) {
                    $this->semanticError("Identificador '$name' ya ha sido declarado", $decl->line, $decl->col);
                }
            }
        } else {
            foreach ($names as $name) {
                $val = GolampiValue::defaultFor($type);
                if (!$env->declare($name, $val, $decl->line, $decl->col)) {
                    $this->semanticError("Identificador '$name' ya ha sido declarado", $decl->line, $decl->col);
                }
            }
        }
    }

    // Crear arreglo con valores por defecto
    private function createDefaultArray(array $dims, string $type): array {
        if (count($dims) === 1) {
            $arr = [];
            for ($i = 0; $i < $dims[0]; $i++) {
                $arr[] = GolampiValue::defaultFor($type);
            }
            return $arr;
        }
        $arr = [];
        $subDims = array_slice($dims, 1);
        for ($i = 0; $i < $dims[0]; $i++) {
            $arr[] = new GolampiValue('arreglo', $this->createDefaultArray($subDims, $type), $subDims);
        }
        return $arr;
    }

    // Ejecutar declaración corta
    private function execShortVarDecl(ShortVarDeclNode $decl, Environment $env): void {
        // Evaluar todos los valores primero (para múltiple retorno)
        $vals = [];
        if (count($decl->values) === 1 && count($decl->names) > 1) {
            // Posible múltiple retorno de función
            $v = $this->evalExpr($decl->values[0], $env);
            if (is_array($v->value) && $v->type === 'multi_return') {
                $vals = $v->value;
            } else {
                $vals = [$v];
            }
        } else if (count($decl->names) !== count($decl->values)) {
            $this->semanticError("Número de variables y valores no coincide", $decl->line, $decl->col);
            return;
        } else {
            foreach ($decl->values as $valExpr) {
                $vals[] = $this->evalExpr($valExpr, $env);
            }
        }
        if (count($decl->names) !== count($vals)) {
            $this->semanticError("Número de variables y valores no coincide", $decl->line, $decl->col);
            return;
        }
        foreach ($decl->names as $i => $name) {
            $val = $vals[$i];
            // Si el valor es un arreglo literal, mantener tipo arreglo
            if (!$env->declare($name, $val, $decl->line, $decl->col)) {
                // En declaración corta, si la variable ya existe, es asignación
                if (!$env->set($name, $val)) {
                    $this->semanticError("Identificador '$name' ya ha sido declarado", $decl->line, $decl->col);
                }
            }
        }
    }

    // Ejecutar declaración de constante
    private function execConstDecl(ConstDeclNode $decl, Environment $env): void {
        $val = $this->evalExpr($decl->value, $env);
        $typed = $this->castToType($val, $decl->type, $decl->line, $decl->col);
        if (!$env->declare($decl->name, $typed, $decl->line, $decl->col, true)) {
            $this->semanticError("Identificador '{$decl->name}' ya ha sido declarado", $decl->line, $decl->col);
        }
    }

    // Ejecutar asignación
    private function execAssign(AssignNode $stmt, Environment $env): void {
        $value = $this->evalExpr($stmt->value, $env);

        // Desreferencia (*ptr = val)
        if ($stmt->target instanceof DereferenceNode) {
            $inner = $stmt->target->operand;
            if ($inner instanceof IdentifierNode) {
                $ref = $env->get($inner->name);
                if ($ref !== null && $ref->type === 'reference') {
                    $refData = $ref->value;
                    $refData['env']->setDirect($refData['name'], $value);
                    return;
                }
            }
            $this->semanticError("Desreferencia inválida", $stmt->line, $stmt->col);
            return;
        }

        // Acceso a arreglo
        if ($stmt->target instanceof ArrayAccessNode) {
            $this->setArrayElement($stmt->target, $value, $env, $stmt->op);
            return;
        }

        // Variable simple
        if ($stmt->target instanceof IdentifierNode) {
            $name = $stmt->target->name;

            // Verificar constante
            if ($env->isConst($name)) {
                $this->semanticError("No se puede modificar la constante '$name'", $stmt->line, $stmt->col);
                return;
            }

            $current = $env->get($name);
            if ($current === null) {
                $this->semanticError("Variable '$name' no declarada en el ámbito actual", $stmt->line, $stmt->col);
                return;
            }

            // Operadores de asignación compuesta
            if ($stmt->op !== '=') {
                $value = $this->applyAssignOp($stmt->op, $current, $value, $stmt->line, $stmt->col);
            }

            $env->set($name, $value);
            return;
        }

        $this->semanticError("Objetivo de asignación inválido", $stmt->line, $stmt->col);
    }

    // Asignar elemento de arreglo
    private function setArrayElement(ArrayAccessNode $access, GolampiValue $value, Environment $env, string $op): void {
        // Resolver la cadena de accesos
        $indices = [];
        $current = $access;
        while ($current instanceof ArrayAccessNode) {
            $idx = $this->evalExpr($current->index, $env)->toInt();
            array_unshift($indices, $idx);
            $current = $current->array;
        }
        if (!($current instanceof IdentifierNode)) {
            $this->semanticError("Acceso a arreglo inválido", $access->line, $access->col);
            return;
        }
        $arrVal = $env->get($current->name);
        if ($arrVal === null) {
            $this->semanticError("Variable '{$current->name}' no declarada", $access->line, $access->col);
            return;
        }
        // Navegar al elemento
        $arr = &$this->getArrayRef($arrVal, $current->name, $env);
        if ($arr === null) return;

        $target = &$arr;
        for ($i = 0; $i < count($indices); $i++) {
            $idx = $indices[$i];
            if ($i < count($indices) - 1) {
                if (!isset($target[$idx]) || !($target[$idx] instanceof GolampiValue)) break;
                $target = &$target[$idx]->value;
            } else {
                if ($op !== '=') {
                    $value = $this->applyAssignOp($op, $target[$idx], $value, $access->line, $access->col);
                }
                $target[$idx] = $value;
            }
        }
    }

    // Obtener referencia al arreglo interno
    private function &getArrayRef(GolampiValue $arrVal, string $name, Environment $env): ?array {
        $null = null;
        if (!is_array($arrVal->value)) {
            return $null;
        }
        return $arrVal->value;
    }

    // Aplicar operador de asignación compuesta
    private function applyAssignOp(string $op, GolampiValue $current, GolampiValue $value, int $line, int $col): GolampiValue {
        switch ($op) {
            case '+=': return $this->arithmeticOp('+', $current, $value, $line, $col);
            case '-=': return $this->arithmeticOp('-', $current, $value, $line, $col);
            case '*=': return $this->arithmeticOp('*', $current, $value, $line, $col);
            case '/=': return $this->arithmeticOp('/', $current, $value, $line, $col);
            default: return $value;
        }
    }

    // Ejecutar if
    private function execIf(IfNode $stmt, Environment $env): void {
        $condVal = $this->evalExpr($stmt->condition, $env);
        if ($condVal->toBool()) {
            $ifEnv = $env->createChild($env->scopeName);
            $this->execBlock($stmt->body, $ifEnv);
        } elseif ($stmt->elseBody !== null) {
            if ($stmt->elseBody instanceof IfNode) {
                $this->execIf($stmt->elseBody, $env);
            } else {
                $elseEnv = $env->createChild($env->scopeName);
                $this->execBlock($stmt->elseBody, $elseEnv);
            }
        }
    }

    // Ejecutar switch
    private function execSwitch(SwitchNode $stmt, Environment $env): void {
        $switchVal = $this->evalExpr($stmt->expr, $env);
        $matched = false;
        foreach ($stmt->cases as $case) {
            foreach ($case->values as $caseExpr) {
                $caseVal = $this->evalExpr($caseExpr, $env);
                if ($this->valuesEqual($switchVal, $caseVal)) {
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                $caseEnv = $env->createChild($env->scopeName);
                try {
                    foreach ($case->body as $s) $this->execStatement($s, $caseEnv);
                } catch (BreakSignal $b) { /* break del switch */ }
                return;
            }
        }
        if ($stmt->default !== null) {
            $defEnv = $env->createChild($env->scopeName);
            try {
                foreach ($stmt->default->body as $s) $this->execStatement($s, $defEnv);
            } catch (BreakSignal $b) { /* break del switch */ }
        }
    }

    // Ejecutar for
    private function execFor(ForNode $stmt, Environment $env): void {
        $forEnv = $env->createChild($env->scopeName);

        // Inicialización
        if ($stmt->init !== null) {
            $this->execStatement($stmt->init, $forEnv);
        }

        $maxIter = 100000; // Protección contra bucles infinitos
        $iter = 0;
        while ($iter++ < $maxIter) {
            // Condición
            if ($stmt->condition !== null) {
                $condVal = $this->evalExpr($stmt->condition, $forEnv);
                if (!$condVal->toBool()) break;
            }

            // Cuerpo
            try {
                $bodyEnv = $forEnv->createChild($env->scopeName);
                $this->execBlock($stmt->body, $bodyEnv);
            } catch (BreakSignal $b) {
                break;
            } catch (ContinueSignal $c) {
                // Continuar con la siguiente iteración
            }

            // Post
            if ($stmt->post !== null) {
                $this->execStatement($stmt->post, $forEnv);
            }
        }
    }

    // Ejecutar return
    private function execReturn(ReturnNode $stmt, Environment $env): void {
        $vals = [];
        foreach ($stmt->values as $expr) {
            $vals[] = $this->evalExpr($expr, $env);
        }
        throw new ReturnSignal($vals);
    }

    // Ejecutar incremento/decremento
    private function execIncDec(IncDecNode $stmt, Environment $env): void {
        $val = $env->get($stmt->name);
        if ($val === null) {
            $this->semanticError("Variable '{$stmt->name}' no declarada", $stmt->line, $stmt->col);
            return;
        }
        if ($stmt->op === '++') {
            $newVal = new GolampiValue($val->type, $val->value + 1);
        } else {
            $newVal = new GolampiValue($val->type, $val->value - 1);
        }
        $env->set($stmt->name, $newVal);
    }

    // --- Evaluación de expresiones ---

    public function evalExpr(ASTNode $expr, Environment $env): GolampiValue {
        if ($expr instanceof IntLitNode) return new GolampiValue('int32', $expr->value);
        if ($expr instanceof FloatLitNode) return new GolampiValue('float32', $expr->value);
        if ($expr instanceof StringLitNode) return new GolampiValue('string', $expr->value);
        if ($expr instanceof RuneLitNode) return new GolampiValue('rune', $expr->value);
        if ($expr instanceof BoolLitNode) return new GolampiValue('bool', $expr->value);
        if ($expr instanceof NilLitNode) return GolampiValue::nil();

        if ($expr instanceof IdentifierNode) {
            $val = $env->get($expr->name);
            if ($val === null) {
                $this->semanticError("Variable '{$expr->name}' no declarada en el ámbito actual", $expr->line, $expr->col);
                return GolampiValue::nil();
            }
            return $val;
        }

        if ($expr instanceof BinaryOpNode) return $this->evalBinaryOp($expr, $env);
        if ($expr instanceof UnaryOpNode) return $this->evalUnaryOp($expr, $env);
        if ($expr instanceof ArrayAccessNode) return $this->evalArrayAccess($expr, $env);
        if ($expr instanceof FuncCallNode) return $this->evalFuncCall($expr, $env);
        if ($expr instanceof PrintlnNode) return $this->evalPrintln($expr, $env);
        if ($expr instanceof LenNode) return $this->evalLen($expr, $env);
        if ($expr instanceof NowNode) return new GolampiValue('string', date('Y-m-d H:i:s'));
        if ($expr instanceof SubstrNode) return $this->evalSubstr($expr, $env);
        if ($expr instanceof TypeOfNode) return $this->evalTypeOf($expr, $env);
        if ($expr instanceof ArrayLitNode) return $this->evalArrayLit($expr, $env);
        if ($expr instanceof ReferenceNode) return $this->evalReference($expr, $env);
        if ($expr instanceof DereferenceNode) return $this->evalDereference($expr, $env);

        return GolampiValue::nil();
    }

    // Operación binaria
    private function evalBinaryOp(BinaryOpNode $expr, Environment $env): GolampiValue {
        // Cortocircuito para && y ||
        if ($expr->op === '&&') {
            $left = $this->evalExpr($expr->left, $env);
            if (!$left->toBool()) return new GolampiValue('bool', false);
            $right = $this->evalExpr($expr->right, $env);
            return new GolampiValue('bool', $right->toBool());
        }
        if ($expr->op === '||') {
            $left = $this->evalExpr($expr->left, $env);
            if ($left->toBool()) return new GolampiValue('bool', true);
            $right = $this->evalExpr($expr->right, $env);
            return new GolampiValue('bool', $right->toBool());
        }

        $left = $this->evalExpr($expr->left, $env);
        $right = $this->evalExpr($expr->right, $env);

        // Verificar nil
        if ($left->isNil() || $right->isNil()) {
            return GolampiValue::nil();
        }

        // Operadores aritméticos
        if (in_array($expr->op, ['+', '-', '*', '/', '%'])) {
            return $this->arithmeticOp($expr->op, $left, $right, $expr->line, $expr->col);
        }

        // Operadores relacionales
        if (in_array($expr->op, ['==', '!=', '<', '>', '<=', '>='])) {
            return $this->relationalOp($expr->op, $left, $right, $expr->line, $expr->col);
        }

        return GolampiValue::nil();
    }

    // Operación aritmética con tablas de tipos
    private function arithmeticOp(string $op, GolampiValue $left, GolampiValue $right, int $line, int $col): GolampiValue {
        $lt = $left->type;
        $rt = $right->type;

        // Suma de strings
        if ($op === '+' && ($lt === 'string' || $rt === 'string')) {
            if ($lt === 'string' && $rt === 'string') {
                return new GolampiValue('string', $left->value . $right->value);
            }
            return GolampiValue::nil();
        }

        // Multiplicación string * int o int * string
        if ($op === '*') {
            if ($lt === 'string' && $rt === 'int32') {
                return new GolampiValue('string', str_repeat($left->value, $right->value));
            }
            if ($lt === 'int32' && $rt === 'string') {
                return new GolampiValue('string', str_repeat($right->value, $left->value));
            }
        }

        // Tabla de tipos resultado
        $resultType = $this->getArithResultType($op, $lt, $rt);
        if ($resultType === null) {
            $this->semanticError("Operación '$op' inválida entre '$lt' y '$rt'", $line, $col);
            return GolampiValue::nil();
        }

        $lv = ($resultType === 'float32') ? $left->toFloat() : $left->toInt();
        $rv = ($resultType === 'float32') ? $right->toFloat() : $right->toInt();

        switch ($op) {
            case '+': $result = $lv + $rv; break;
            case '-': $result = $lv - $rv; break;
            case '*': $result = $lv * $rv; break;
            case '/':
                if ($rv == 0) {
                    $this->semanticError("División por cero", $line, $col);
                    return GolampiValue::nil();
                }
                $result = ($resultType === 'int32') ? intdiv((int)$lv, (int)$rv) : $lv / $rv;
                break;
            case '%':
                if ($rv == 0) {
                    $this->semanticError("Módulo por cero", $line, $col);
                    return GolampiValue::nil();
                }
                $result = (int)$lv % (int)$rv;
                break;
            default: $result = 0;
        }

        return new GolampiValue($resultType, $result);
    }

    // Tabla de tipos resultado para operaciones aritméticas
    private function getArithResultType(string $op, string $lt, string $rt): ?string {
        // Convertir bool a int32 para aritmética
        if ($lt === 'bool') $lt = 'int32';
        if ($rt === 'bool') $rt = 'int32';
        // rune se trata como int32
        if ($lt === 'rune') $lt = 'int32';
        if ($rt === 'rune') $rt = 'int32';

        $numTypes = ['int32', 'float32'];
        if (!in_array($lt, $numTypes) || !in_array($rt, $numTypes)) {
            return null;
        }

        if ($op === '%') return 'int32';
        if ($lt === 'float32' || $rt === 'float32') return 'float32';
        return 'int32';
    }

    // Operación relacional
    private function relationalOp(string $op, GolampiValue $left, GolampiValue $right, int $line, int $col): GolampiValue {
        // Comparación de strings
        if ($left->type === 'string' && $right->type === 'string') {
            $cmp = strcmp($left->value, $right->value);
            switch ($op) {
                case '==': return new GolampiValue('bool', $cmp === 0);
                case '!=': return new GolampiValue('bool', $cmp !== 0);
                case '<': return new GolampiValue('bool', $cmp < 0);
                case '>': return new GolampiValue('bool', $cmp > 0);
                case '<=': return new GolampiValue('bool', $cmp <= 0);
                case '>=': return new GolampiValue('bool', $cmp >= 0);
            }
        }

        // Bool solo soporta == y !=
        if ($left->type === 'bool' && $right->type === 'bool') {
            if ($op === '==') return new GolampiValue('bool', $left->value === $right->value);
            if ($op === '!=') return new GolampiValue('bool', $left->value !== $right->value);
            $this->semanticError("Operación '$op' no válida para tipo bool", $line, $col);
            return GolampiValue::nil();
        }

        // Numéricos
        $lv = $left->toFloat();
        $rv = $right->toFloat();
        switch ($op) {
            case '==': return new GolampiValue('bool', $lv == $rv);
            case '!=': return new GolampiValue('bool', $lv != $rv);
            case '<': return new GolampiValue('bool', $lv < $rv);
            case '>': return new GolampiValue('bool', $lv > $rv);
            case '<=': return new GolampiValue('bool', $lv <= $rv);
            case '>=': return new GolampiValue('bool', $lv >= $rv);
        }
        return GolampiValue::nil();
    }

    // Operación unaria
    private function evalUnaryOp(UnaryOpNode $expr, Environment $env): GolampiValue {
        $val = $this->evalExpr($expr->operand, $env);
        if ($val->isNil()) return GolampiValue::nil();
        if ($expr->op === '-') {
            if ($val->type === 'int32') return new GolampiValue('int32', -$val->value);
            if ($val->type === 'float32') return new GolampiValue('float32', -$val->value);
            if ($val->type === 'rune') return new GolampiValue('int32', -mb_ord($val->value));
        }
        if ($expr->op === '!') {
            return new GolampiValue('bool', !$val->toBool());
        }
        return GolampiValue::nil();
    }

    // Acceso a arreglo
    private function evalArrayAccess(ArrayAccessNode $expr, Environment $env): GolampiValue {
        $arr = $this->evalExpr($expr->array, $env);
        $idx = $this->evalExpr($expr->index, $env)->toInt();
        if (!is_array($arr->value)) {
            $this->semanticError("No es un arreglo", $expr->line, $expr->col);
            return GolampiValue::nil();
        }
        if ($idx < 0 || $idx >= count($arr->value)) {
            $this->semanticError("Índice $idx fuera de rango", $expr->line, $expr->col);
            return GolampiValue::nil();
        }
        $element = $arr->value[$idx];
        if ($element instanceof GolampiValue) return $element;
        return GolampiValue::nil();
    }

    // Llamada a función
    private function evalFuncCall(FuncCallNode $expr, Environment $env): GolampiValue {
        $name = $expr->name;
        // Verificar que no sea llamada a main
        if ($name === 'main') {
            $this->semanticError("No se puede llamar explícitamente a 'main'", $expr->line, $expr->col);
            return GolampiValue::nil();
        }
        if (!isset($this->functions[$name])) {
            $this->semanticError("Función '$name' no declarada", $expr->line, $expr->col);
            return GolampiValue::nil();
        }
        $func = $this->functions[$name];
        $funcEnv = $this->globalEnv->createChild($name);

        // Evaluar argumentos
        $args = [];
        foreach ($expr->args as $argExpr) {
            $args[] = $argExpr;
        }

        if (count($args) !== count($func->params)) {
            $this->semanticError("Función '$name' espera " . count($func->params) . " argumentos, se recibieron " . count($args), $expr->line, $expr->col);
            return GolampiValue::nil();
        }

        // Pasar parámetros
        foreach ($func->params as $i => $param) {
            if ($param['isPointer']) {
                // Paso por referencia
                $argExpr = $args[$i];
                if ($argExpr instanceof ReferenceNode) {
                    $ref = $env->getRef($argExpr->name);
                    if ($ref !== null) {
                        $refVal = new GolampiValue('reference', $ref);
                        $funcEnv->declare($param['name'], $refVal, $expr->line, $expr->col);
                    }
                } else {
                    $val = $this->evalExpr($argExpr, $env);
                    $funcEnv->declare($param['name'], $val, $expr->line, $expr->col);
                }
            } else {
                // Paso por valor
                $val = $this->evalExpr($args[$i], $env);
                // Para arreglos, hacer copia profunda
                if (is_array($val->value)) {
                    $val = $this->deepCopyValue($val);
                }
                $funcEnv->declare($param['name'], $val, $expr->line, $expr->col);
            }
        }

        // Ejecutar cuerpo
        try {
            $this->execBlock($func->body, $funcEnv);
        } catch (ReturnSignal $r) {
            if (count($r->values) === 1) return $r->values[0];
            if (count($r->values) > 1) {
                return new GolampiValue('multi_return', $r->values);
            }
        }
        return GolampiValue::nil();
    }

    // Copia profunda de valor
    private function deepCopyValue(GolampiValue $val): GolampiValue {
        if (is_array($val->value)) {
            $copy = [];
            foreach ($val->value as $item) {
                if ($item instanceof GolampiValue) {
                    $copy[] = $this->deepCopyValue($item);
                } else {
                    $copy[] = $item;
                }
            }
            return new GolampiValue($val->type, $copy, $val->arrayDims);
        }
        return new GolampiValue($val->type, $val->value, $val->arrayDims);
    }

    // fmt.Println
    private function evalPrintln(PrintlnNode $expr, Environment $env): GolampiValue {
        $parts = [];
        foreach ($expr->args as $arg) {
            $val = $this->evalExpr($arg, $env);
            $parts[] = $val->toPrintString();
        }
        $this->output[] = implode(' ', $parts);
        return GolampiValue::nil();
    }

    // len()
    private function evalLen(LenNode $expr, Environment $env): GolampiValue {
        $val = $this->evalExpr($expr->arg, $env);
        if ($val->type === 'string') {
            return new GolampiValue('int32', mb_strlen($val->value));
        }
        if (is_array($val->value)) {
            return new GolampiValue('int32', count($val->value));
        }
        $this->semanticError("len() requiere string o arreglo", $expr->line, $expr->col);
        return GolampiValue::nil();
    }

    // substr()
    private function evalSubstr(SubstrNode $expr, Environment $env): GolampiValue {
        $str = $this->evalExpr($expr->str, $env);
        $start = $this->evalExpr($expr->start, $env)->toInt();
        $length = $this->evalExpr($expr->length, $env)->toInt();
        if ($str->type !== 'string') {
            $this->semanticError("substr() requiere string como primer argumento", $expr->line, $expr->col);
            return GolampiValue::nil();
        }
        if ($start < 0 || $start + $length > mb_strlen($str->value)) {
            $this->semanticError("Índices inválidos en substr()", $expr->line, $expr->col);
            return GolampiValue::nil();
        }
        return new GolampiValue('string', mb_substr($str->value, $start, $length));
    }

    // typeOf()
    private function evalTypeOf(TypeOfNode $expr, Environment $env): GolampiValue {
        $val = $this->evalExpr($expr->arg, $env);
        $typeName = $val->type;
        if ($typeName === 'arreglo') $typeName = 'array';
        return new GolampiValue('string', $typeName);
    }

    // Literal de arreglo
    private function evalArrayLit(ArrayLitNode $expr, Environment $env): GolampiValue {
        $values = [];
        foreach ($expr->values as $v) {
            if ($v instanceof ArrayLitNode && empty($v->dims)) {
                // Sub-arreglo {val, val, ...}
                $subVals = [];
                foreach ($v->values as $sv) {
                    $subVals[] = $this->evalExpr($sv, $env);
                }
                $values[] = new GolampiValue('arreglo', $subVals);
            } else {
                $values[] = $this->evalExpr($v, $env);
            }
        }
        $dims = [];
        foreach ($expr->dims as $d) {
            $dims[] = $this->evalExpr($d, $env)->toInt();
        }
        return new GolampiValue('arreglo', $values, $dims);
    }

    // Referencia (&var)
    private function evalReference(ReferenceNode $expr, Environment $env): GolampiValue {
        $ref = $env->getRef($expr->name);
        if ($ref === null) {
            $this->semanticError("Variable '{$expr->name}' no declarada", $expr->line, $expr->col);
            return GolampiValue::nil();
        }
        return new GolampiValue('reference', $ref);
    }

    // Desreferencia (*var)
    private function evalDereference(DereferenceNode $expr, Environment $env): GolampiValue {
        $val = $this->evalExpr($expr->operand, $env);
        if ($val->type === 'reference' && is_array($val->value)) {
            $refData = $val->value;
            return $refData['env']->get($refData['name']) ?? GolampiValue::nil();
        }
        $this->semanticError("Desreferencia inválida", $expr->line, $expr->col);
        return GolampiValue::nil();
    }

    // Comparar valores
    private function valuesEqual(GolampiValue $a, GolampiValue $b): bool {
        if ($a->type === 'string' && $b->type === 'string') return $a->value === $b->value;
        if ($a->type === 'bool' && $b->type === 'bool') return $a->value === $b->value;
        return $a->toFloat() == $b->toFloat();
    }

    // Convertir a tipo
    private function castToType(GolampiValue $val, string $type, int $line, int $col): GolampiValue {
        switch ($type) {
            case 'int32': case 'int': return new GolampiValue('int32', $val->toInt());
            case 'float32': return new GolampiValue('float32', $val->toFloat());
            case 'bool': return new GolampiValue('bool', $val->toBool());
            case 'rune':
                if ($val->type === 'rune') return $val;
                if ($val->type === 'string') return new GolampiValue('rune', mb_substr($val->value, 0, 1));
                return new GolampiValue('rune', mb_chr($val->toInt()));
            case 'string': return new GolampiValue('string', $val->toPrintString());
            default: return $val;
        }
    }
}

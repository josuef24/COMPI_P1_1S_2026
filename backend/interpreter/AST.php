<?php
// AST - Nodos del Árbol Sintáctico Abstracto
namespace Interpreter;

// Nodo base
class ASTNode {
    public int $line;
    public int $col;
    public function __construct(int $line = 0, int $col = 0) {
        $this->line = $line;
        $this->col = $col;
    }
}

// Programa
class ProgramNode extends ASTNode {
    public array $declarations = [];
}

// Declaración de función
class FuncDeclNode extends ASTNode {
    public string $name;
    public array $params = [];       // [[name, type, isPointer], ...]
    public array $returnTypes = [];  // [type, ...]
    public BlockNode $body;
}

// Bloque de sentencias
class BlockNode extends ASTNode {
    public array $statements = [];
}

// Declaración larga de variable
class VarDeclNode extends ASTNode {
    public array $names = [];
    public ?string $type = null;
    public ?array $arrayDims = null; // dimensiones del arreglo
    public ?array $values = null;    // expresiones de inicialización
    public ?ASTNode $arrayInit = null;
}

// Declaración corta de variable
class ShortVarDeclNode extends ASTNode {
    public array $names = [];
    public array $values = [];
}

// Declaración de constante
class ConstDeclNode extends ASTNode {
    public string $name;
    public string $type;
    public ASTNode $value;
}

// Asignación
class AssignNode extends ASTNode {
    public ASTNode $target;
    public string $op;
    public ASTNode $value;
}

// Acceso a arreglo
class ArrayAccessNode extends ASTNode {
    public ASTNode $array;
    public ASTNode $index;
}

// If
class IfNode extends ASTNode {
    public ASTNode $condition;
    public BlockNode $body;
    public ?ASTNode $elseBody = null; // BlockNode o IfNode
}

// Switch
class SwitchNode extends ASTNode {
    public ASTNode $expr;
    public array $cases = [];    // [CaseNode, ...]
    public ?CaseNode $default = null;
}

// Case
class CaseNode extends ASTNode {
    public ?array $values = null; // null = default
    public array $body = [];
}

// For
class ForNode extends ASTNode {
    public ?ASTNode $init = null;
    public ?ASTNode $condition = null;
    public ?ASTNode $post = null;
    public BlockNode $body;
}

// Return
class ReturnNode extends ASTNode {
    public array $values = [];
}

// Break
class BreakNode extends ASTNode {}

// Continue
class ContinueNode extends ASTNode {}

// Operación binaria
class BinaryOpNode extends ASTNode {
    public ASTNode $left;
    public string $op;
    public ASTNode $right;
}

// Operación unaria
class UnaryOpNode extends ASTNode {
    public string $op;
    public ASTNode $operand;
}

// Literal entero
class IntLitNode extends ASTNode {
    public int $value;
    public function __construct(int $val, int $line, int $col) {
        parent::__construct($line, $col);
        $this->value = $val;
    }
}

// Literal flotante
class FloatLitNode extends ASTNode {
    public float $value;
    public function __construct(float $val, int $line, int $col) {
        parent::__construct($line, $col);
        $this->value = $val;
    }
}

// Literal string
class StringLitNode extends ASTNode {
    public string $value;
    public function __construct(string $val, int $line, int $col) {
        parent::__construct($line, $col);
        $this->value = $val;
    }
}

// Literal rune
class RuneLitNode extends ASTNode {
    public string $value;
    public function __construct(string $val, int $line, int $col) {
        parent::__construct($line, $col);
        $this->value = $val;
    }
}

// Literal booleano
class BoolLitNode extends ASTNode {
    public bool $value;
    public function __construct(bool $val, int $line, int $col) {
        parent::__construct($line, $col);
        $this->value = $val;
    }
}

// Literal nil
class NilLitNode extends ASTNode {}

// Identificador
class IdentifierNode extends ASTNode {
    public string $name;
    public function __construct(string $name, int $line, int $col) {
        parent::__construct($line, $col);
        $this->name = $name;
    }
}

// Llamada a función
class FuncCallNode extends ASTNode {
    public string $name;
    public array $args = [];
}

// fmt.Println
class PrintlnNode extends ASTNode {
    public array $args = [];
}

// len()
class LenNode extends ASTNode {
    public ASTNode $arg;
}

// now()
class NowNode extends ASTNode {}

// substr()
class SubstrNode extends ASTNode {
    public ASTNode $str;
    public ASTNode $start;
    public ASTNode $length;
}

// typeOf()
class TypeOfNode extends ASTNode {
    public ASTNode $arg;
}

// Literal de arreglo
class ArrayLitNode extends ASTNode {
    public array $dims = [];
    public string $elementType;
    public array $values = [];
}

// Referencia (&var)
class ReferenceNode extends ASTNode {
    public string $name;
}

// Desreferencia (*var)
class DereferenceNode extends ASTNode {
    public ASTNode $operand;
}

// Incremento/Decremento
class IncDecNode extends ASTNode {
    public string $name;
    public string $op; // ++ o --
}

// Sentencia de expresión
class ExprStmtNode extends ASTNode {
    public ASTNode $expr;
}

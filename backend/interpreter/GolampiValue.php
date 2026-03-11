<?php
// GolampiValue - Wrapper de valores con tipo
namespace Interpreter;

class GolampiValue {
    public string $type;   // int32, float32, bool, rune, string, array, nil, function, reference
    public $value;          // valor PHP nativo
    public ?array $arrayDims = null; // dimensiones si es arreglo

    public function __construct(string $type, $value, ?array $arrayDims = null) {
        $this->type = $type;
        $this->value = $value;
        $this->arrayDims = $arrayDims;
    }

    // Valores por defecto según tipo
    public static function defaultFor(string $type): self {
        switch ($type) {
            case 'int32': case 'int': return new self('int32', 0);
            case 'float32': return new self('float32', 0.0);
            case 'bool': return new self('bool', false);
            case 'rune': return new self('rune', "\0");
            case 'string': return new self('string', '');
            default: return new self('nil', null);
        }
    }

    // Crear valor nil
    public static function nil(): self {
        return new self('nil', null);
    }

    // Verificar si es nil
    public function isNil(): bool {
        return $this->type === 'nil' || $this->value === null;
    }

    // Representación para impresión
    public function toPrintString(): string {
        if ($this->isNil()) return 'nil';
        switch ($this->type) {
            case 'int32': return (string)(int)$this->value;
            case 'float32':
                $v = (float)$this->value;
                if ($v == (int)$v) return number_format($v, 1, '.', '');
                return (string)$v;
            case 'bool': return $this->value ? 'true' : 'false';
            case 'rune': return $this->value;
            case 'string': return $this->value;
            case 'array': return $this->arrayToString();
            default: return (string)$this->value;
        }
    }

    // Representación para tabla de símbolos
    public function toDisplayString(): string {
        if ($this->isNil()) return '—';
        switch ($this->type) {
            case 'int32': return (string)(int)$this->value;
            case 'float32': return (string)(float)$this->value;
            case 'bool': return $this->value ? 'true' : 'false';
            case 'rune': return "'" . $this->value . "'";
            case 'string': return '"' . $this->value . '"';
            case 'array': return $this->arrayToString();
            case 'function': return '—';
            default: return '—';
        }
    }

    // Arreglo a string
    private function arrayToString(): string {
        if (!is_array($this->value)) return '—';
        $parts = [];
        foreach ($this->value as $v) {
            if ($v instanceof GolampiValue) {
                $parts[] = $v->toPrintString();
            } else {
                $parts[] = (string)$v;
            }
        }
        return '{' . implode(',', $parts) . '}';
    }

    // Conversión a booleano
    public function toBool(): bool {
        if ($this->type === 'bool') return (bool)$this->value;
        return !$this->isNil();
    }

    // Conversión a int
    public function toInt(): int {
        switch ($this->type) {
            case 'int32': return (int)$this->value;
            case 'float32': return (int)$this->value;
            case 'bool': return $this->value ? 1 : 0;
            case 'rune': return mb_ord($this->value);
            default: return 0;
        }
    }

    // Conversión a float
    public function toFloat(): float {
        switch ($this->type) {
            case 'int32': return (float)$this->value;
            case 'float32': return (float)$this->value;
            case 'bool': return $this->value ? 1.0 : 0.0;
            case 'rune': return (float)mb_ord($this->value);
            default: return 0.0;
        }
    }
}

<?php
// Environment - Tabla de símbolos y manejo de ámbitos (scopes)
namespace Interpreter;

class Environment {
    // Ámbito padre
    private ?Environment $parent;
    // Nombre del ámbito
    public string $scopeName;
    // Variables del ámbito
    private array $variables = [];
    // Constantes del ámbito
    private array $constants = [];
    // Registro para reporte de tabla de símbolos
    private static array $symbolReport = [];

    public function __construct(?Environment $parent = null, string $scopeName = 'global') {
        $this->parent = $parent;
        $this->scopeName = $scopeName;
    }

    // Declarar variable
    public function declare(string $name, GolampiValue $value, int $line, int $col, bool $isConst = false): bool {
        if (isset($this->variables[$name]) || isset($this->constants[$name])) {
            return false; // Ya declarada en este ámbito
        }
        if ($isConst) {
            $this->constants[$name] = $value;
        } else {
            $this->variables[$name] = $value;
        }
        // Registrar en tabla de símbolos
        self::$symbolReport[] = [
            'id' => $name,
            'type' => $value->type,
            'scope' => $this->scopeName,
            'value' => $value->toDisplayString(),
            'line' => $line,
            'col' => $col,
        ];
        return true;
    }

    // Obtener variable
    public function get(string $name): ?GolampiValue {
        if (isset($this->constants[$name])) return $this->constants[$name];
        if (isset($this->variables[$name])) return $this->variables[$name];
        if ($this->parent !== null) return $this->parent->get($name);
        return null;
    }

    // Asignar variable existente
    public function set(string $name, GolampiValue $value): bool {
        // No se puede reasignar constantes
        if (isset($this->constants[$name])) return false;
        if (isset($this->variables[$name])) {
            $this->variables[$name] = $value;
            $this->updateSymbolReport($name, $value);
            return true;
        }
        if ($this->parent !== null) return $this->parent->set($name, $value);
        return false;
    }

    // Verificar si es constante
    public function isConst(string $name): bool {
        if (isset($this->constants[$name])) return true;
        if ($this->parent !== null) return $this->parent->isConst($name);
        return false;
    }

    // Obtener referencia (para punteros)
    public function getRef(string $name): ?array {
        if (isset($this->variables[$name])) {
            return ['env' => $this, 'name' => $name];
        }
        if ($this->parent !== null) return $this->parent->getRef($name);
        return null;
    }

    // Asignar por referencia
    public function setDirect(string $name, GolampiValue $value): void {
        $this->variables[$name] = $value;
        $this->updateSymbolReport($name, $value);
    }

    // Actualizar reporte de tabla de símbolos
    private function updateSymbolReport(string $name, GolampiValue $value): void {
        foreach (self::$symbolReport as &$entry) {
            if ($entry['id'] === $name && $entry['scope'] === $this->scopeName) {
                $entry['value'] = $value->toDisplayString();
                $entry['type'] = $value->type;
            }
        }
    }

    // Crear sub-ámbito
    public function createChild(string $scopeName): Environment {
        return new Environment($this, $scopeName);
    }

    // Obtener reporte de tabla de símbolos
    public static function getSymbolReport(): array {
        return self::$symbolReport;
    }

    // Limpiar reporte
    public static function clearSymbolReport(): void {
        self::$symbolReport = [];
    }
}

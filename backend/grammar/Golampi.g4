// Gramática ANTLR4 para el lenguaje Golampi
grammar Golampi;

// --- Reglas del Parser ---

// Programa principal
program
    : (functionDecl | varDeclStmt | constDeclStmt)* EOF
    ;

// Declaración de función
functionDecl
    : FUNC IDENTIFIER LPAREN paramList? RPAREN returnType? block
    ;

// Lista de parámetros
paramList
    : param (COMMA param)*
    ;

// Parámetro individual
param
    : IDENTIFIER typeSpec
    | IDENTIFIER STAR typeSpec
    ;

// Tipo de retorno
returnType
    : typeSpec
    | LPAREN typeSpec (COMMA typeSpec)* RPAREN
    ;

// Especificación de tipo (incluyendo arreglos y punteros)
typeSpec
    : INT32
    | FLOAT32
    | BOOL_TYPE
    | RUNE_TYPE
    | STRING_TYPE
    | arrayType
    | STAR typeSpec
    ;

// Tipo arreglo
arrayType
    : (LBRACKET expression RBRACKET)+ primitiveType
    ;

// Tipo primitivo
primitiveType
    : INT32
    | FLOAT32
    | BOOL_TYPE
    | RUNE_TYPE
    | STRING_TYPE
    ;

// Bloque de sentencias
block
    : LBRACE statement* RBRACE
    ;

// Sentencias
statement
    : varDeclStmt
    | constDeclStmt
    | shortVarDeclStmt
    | assignmentStmt
    | ifStmt
    | switchStmt
    | forStmt
    | returnStmt
    | breakStmt
    | continueStmt
    | expressionStmt
    | incDecStmt
    ;

// Declaración larga de variable
varDeclStmt
    : VAR idList typeSpec (ASSIGN expressionList)?
    | VAR IDENTIFIER arrayType (ASSIGN arrayLiteral)?
    ;

// Declaración de constante
constDeclStmt
    : CONST IDENTIFIER typeSpec ASSIGN expression
    ;

// Declaración corta de variable
shortVarDeclStmt
    : idList SHORT_ASSIGN expressionList
    ;

// Asignación
assignmentStmt
    : leftValue assignOp expression
    ;

// Valor izquierdo (variable o acceso a arreglo)
leftValue
    : IDENTIFIER (LBRACKET expression RBRACKET)*
    | STAR IDENTIFIER
    ;

// Operador de asignación
assignOp
    : ASSIGN
    | PLUS_ASSIGN
    | MINUS_ASSIGN
    | STAR_ASSIGN
    | SLASH_ASSIGN
    ;

// Incremento/Decremento
incDecStmt
    : IDENTIFIER PLUS_PLUS
    | IDENTIFIER MINUS_MINUS
    ;

// If
ifStmt
    : IF expression block (ELSE ifStmt | ELSE block)?
    ;

// Switch
switchStmt
    : SWITCH expression LBRACE caseClause* defaultClause? RBRACE
    ;

// Cláusula case
caseClause
    : CASE expressionList COLON statement*
    ;

// Cláusula default
defaultClause
    : DEFAULT COLON statement*
    ;

// For
forStmt
    : FOR forInit? SEMICOLON expression? SEMICOLON forPost? block   // for clásico
    | FOR expression block                                          // for condicional
    | FOR block                                                     // for infinito
    ;

// Inicialización del for
forInit
    : shortVarDeclInFor
    | assignmentStmt
    ;

// Declaración corta dentro del for
shortVarDeclInFor
    : idList SHORT_ASSIGN expressionList
    ;

// Post del for
forPost
    : assignmentStmt
    | incDecStmt
    ;

// Return
returnStmt
    : RETURN expressionList?
    ;

// Break
breakStmt
    : BREAK
    ;

// Continue
continueStmt
    : CONTINUE
    ;

// Expresión como sentencia
expressionStmt
    : expression
    ;

// Lista de ids
idList
    : IDENTIFIER (COMMA IDENTIFIER)*
    ;

// Lista de expresiones
expressionList
    : expression (COMMA expression)*
    ;

// Expresiones con precedencia
expression
    : LPAREN expression RPAREN                                              # parenExpr
    | expression LBRACKET expression RBRACKET                               # arrayAccess
    | NOT expression                                                        # notExpr
    | MINUS expression                                                      # unaryMinusExpr
    | AMPERSAND IDENTIFIER                                                  # referenceExpr
    | STAR expression                                                       # dereferenceExpr
    | expression op=(STAR | SLASH | PERCENT) expression                     # mulDivModExpr
    | expression op=(PLUS | MINUS) expression                               # addSubExpr
    | expression op=(LT | GT | LTE | GTE) expression                       # relExpr
    | expression op=(EQUAL | NOT_EQUAL) expression                          # eqExpr
    | expression AND expression                                             # andExpr
    | expression OR expression                                              # orExpr
    | FMT DOT PRINTLN LPAREN expressionList? RPAREN                         # printlnExpr
    | LEN LPAREN expression RPAREN                                          # lenExpr
    | NOW LPAREN RPAREN                                                     # nowExpr
    | SUBSTR LPAREN expression COMMA expression COMMA expression RPAREN     # substrExpr
    | TYPEOF LPAREN expression RPAREN                                       # typeOfExpr
    | IDENTIFIER LPAREN argumentList? RPAREN                                # funcCallExpr
    | arrayLiteral                                                          # arrayLitExpr
    | IDENTIFIER                                                            # idExpr
    | INT_LIT                                                               # intLitExpr
    | FLOAT_LIT                                                             # floatLitExpr
    | STRING_LIT                                                            # stringLitExpr
    | RUNE_LIT                                                              # runeLitExpr
    | TRUE                                                                  # trueLitExpr
    | FALSE                                                                 # falseLitExpr
    | NIL                                                                   # nilLitExpr
    ;

// Lista de argumentos
argumentList
    : expression (COMMA expression)*
    ;

// Literal de arreglo
arrayLiteral
    : LBRACKET expression RBRACKET primitiveType LBRACE expressionList? RBRACE
    | LBRACKET expression RBRACKET arrayType LBRACE arrayInitList? RBRACE
    ;

// Lista de inicialización de arreglos (para multidimensionales)
arrayInitList
    : arrayInitItem (COMMA arrayInitItem)*
    ;

// Item de inicialización
arrayInitItem
    : LBRACE expressionList? RBRACE
    | arrayLiteral
    ;

// --- Tokens Léxicos ---

// Palabras reservadas
VAR         : 'var';
CONST       : 'const';
FUNC        : 'func';
RETURN      : 'return';
IF          : 'if';
ELSE        : 'else';
FOR         : 'for';
SWITCH      : 'switch';
CASE        : 'case';
DEFAULT     : 'default';
BREAK       : 'break';
CONTINUE    : 'continue';
NIL         : 'nil';
TRUE        : 'true';
FALSE       : 'false';
INT32       : 'int32' | 'int';
FLOAT32     : 'float32';
BOOL_TYPE   : 'bool';
RUNE_TYPE   : 'rune';
STRING_TYPE : 'string';
FMT         : 'fmt';
PRINTLN     : 'Println';
LEN         : 'len';
NOW         : 'now';
SUBSTR      : 'substr';
TYPEOF      : 'typeOf';

// Operadores
PLUS        : '+';
MINUS       : '-';
STAR        : '*';
SLASH       : '/';
PERCENT     : '%';
ASSIGN      : '=';
SHORT_ASSIGN: ':=';
PLUS_ASSIGN : '+=';
MINUS_ASSIGN: '-=';
STAR_ASSIGN : '*=';
SLASH_ASSIGN: '/=';
PLUS_PLUS   : '++';
MINUS_MINUS : '--';
EQUAL       : '==';
NOT_EQUAL   : '!=';
LT          : '<';
GT          : '>';
LTE         : '<=';
GTE         : '>=';
AND         : '&&';
OR          : '||';
NOT         : '!';
AMPERSAND   : '&';
DOT         : '.';

// Delimitadores
LPAREN      : '(';
RPAREN      : ')';
LBRACE      : '{';
RBRACE      : '}';
LBRACKET    : '[';
RBRACKET    : ']';
SEMICOLON   : ';';
COLON       : ':';
COMMA       : ',';

// Literales
INT_LIT     : [0-9]+;
FLOAT_LIT   : [0-9]+ '.' [0-9]+;
STRING_LIT  : '"' (~["\\\r\n] | '\\' .)* '"';
RUNE_LIT    : '\'' (~['\\\r\n] | '\\' . | '\\u' [0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F]) '\'';

// Identificador
IDENTIFIER  : [a-zA-Z_][a-zA-Z0-9_]*;

// Comentarios (ignorados)
LINE_COMMENT    : '//' ~[\r\n]* -> skip;
BLOCK_COMMENT   : '/*' .*? '*/' -> skip;

// Espacios en blanco (ignorados)
WS          : [ \t\r\n]+ -> skip;

// Script - Lógica de la GUI del IDE Golampi

// Referencias a elementos del DOM
const codeEditor = document.getElementById('code-editor');
const lineNumbers = document.getElementById('line-numbers');
const consoleOutput = document.getElementById('console-output');
const fileNameSpan = document.getElementById('file-name');
const fileInput = document.getElementById('file-input');

// Botones de acciones
const btnNew = document.getElementById('btn-new');
const btnOpen = document.getElementById('btn-open');
const btnSave = document.getElementById('btn-save');
const btnRun = document.getElementById('btn-run');
const btnClearConsole = document.getElementById('btn-clear-console');

// Botones de reportes
const btnDownloadOutput = document.getElementById('btn-download-output');
const btnDownloadErrors = document.getElementById('btn-download-errors');
const btnDownloadSymbols = document.getElementById('btn-download-symbols');

// Secciones de reportes
const errorsSection = document.getElementById('errors-section');
const symbolsSection = document.getElementById('symbols-section');
const errorsTableContainer = document.getElementById('errors-table-container');
const symbolsTableContainer = document.getElementById('symbols-table-container');

// Estado
let lastOutput = '';
let lastErrors = [];
let lastSymbols = [];
let currentFileName = 'sin_titulo.glp';

// URL del backend
const API_URL = '../backend/api.php';

// --- Números de línea ---
function updateLineNumbers() {
    const lines = codeEditor.value.split('\n').length;
    let nums = '';
    for (let i = 1; i <= lines; i++) {
        nums += i + '\n';
    }
    lineNumbers.textContent = nums;
}

// Sincronizar scroll de líneas con editor
codeEditor.addEventListener('scroll', () => {
    lineNumbers.scrollTop = codeEditor.scrollTop;
});

codeEditor.addEventListener('input', updateLineNumbers);
codeEditor.addEventListener('keydown', (e) => {
    // Soporte para Tab
    if (e.key === 'Tab') {
        e.preventDefault();
        const start = codeEditor.selectionStart;
        const end = codeEditor.selectionEnd;
        codeEditor.value = codeEditor.value.substring(0, start) + '    ' + codeEditor.value.substring(end);
        codeEditor.selectionStart = codeEditor.selectionEnd = start + 4;
        updateLineNumbers();
    }
});

// --- Acciones ---

// Nuevo / Limpiar
btnNew.addEventListener('click', () => {
    codeEditor.value = '';
    consoleOutput.textContent = '';
    currentFileName = 'sin_titulo.glp';
    fileNameSpan.textContent = currentFileName;
    clearReports();
    updateLineNumbers();
});

// Abrir archivo
btnOpen.addEventListener('click', () => fileInput.click());
fileInput.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (!file) return;
    currentFileName = file.name;
    fileNameSpan.textContent = currentFileName;
    const reader = new FileReader();
    reader.onload = (ev) => {
        codeEditor.value = ev.target.result;
        updateLineNumbers();
    };
    reader.readAsText(file);
    fileInput.value = '';
});

// Guardar código
btnSave.addEventListener('click', () => {
    const blob = new Blob([codeEditor.value], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = currentFileName;
    a.click();
    URL.revokeObjectURL(url);
});

// Ejecutar
btnRun.addEventListener('click', async () => {
    const code = codeEditor.value;
    if (!code.trim()) {
        consoleOutput.textContent = '⚠️ No hay código para ejecutar.';
        return;
    }

    consoleOutput.textContent = '⏳ Ejecutando...';
    btnRun.disabled = true;
    btnRun.classList.add('loading');

    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code })
        });

        const data = await response.json();

        // Mostrar output
        lastOutput = data.output || '';
        consoleOutput.textContent = lastOutput || '(Sin salida)';

        // Procesar errores
        lastErrors = data.errors || [];
        if (lastErrors.length > 0) {
            showErrorsTable(lastErrors);
            // Agregar errores a la consola
            consoleOutput.textContent += '\n\n--- Errores ---\n';
            lastErrors.forEach(err => {
                consoleOutput.textContent += `[${err.type}] Línea ${err.line}, Col ${err.col}: ${err.description}\n`;
            });
        } else {
            errorsSection.style.display = 'none';
        }

        // Procesar tabla de símbolos
        lastSymbols = data.symbolTable || [];
        if (lastSymbols.length > 0) {
            showSymbolsTable(lastSymbols);
        } else {
            symbolsSection.style.display = 'none';
        }

        // Habilitar botones de descarga
        btnDownloadOutput.disabled = false;
        btnDownloadErrors.disabled = lastErrors.length === 0;
        btnDownloadSymbols.disabled = lastSymbols.length === 0;

    } catch (err) {
        consoleOutput.textContent = '❌ Error de conexión con el servidor.\n' + err.message;
    }

    btnRun.disabled = false;
    btnRun.classList.remove('loading');
});

// Limpiar consola
btnClearConsole.addEventListener('click', () => {
    consoleOutput.textContent = '';
});

// --- Reportes ---

// Mostrar tabla de errores
function showErrorsTable(errors) {
    errorsSection.style.display = 'block';
    let html = '<table class="report-table"><thead><tr>';
    html += '<th>#</th><th>Tipo</th><th>Descripción</th><th>Línea</th><th>Columna</th>';
    html += '</tr></thead><tbody>';
    errors.forEach(err => {
        const typeClass = err.type === 'Léxico' ? 'error-lexico' :
                          err.type === 'Sintáctico' ? 'error-sintactico' : 'error-semantico';
        html += `<tr>`;
        html += `<td>${err.num}</td>`;
        html += `<td class="${typeClass}">${err.type}</td>`;
        html += `<td>${err.description}</td>`;
        html += `<td>${err.line}</td>`;
        html += `<td>${err.col}</td>`;
        html += `</tr>`;
    });
    html += '</tbody></table>';
    errorsTableContainer.innerHTML = html;
}

// Mostrar tabla de símbolos
function showSymbolsTable(symbols) {
    symbolsSection.style.display = 'block';
    let html = '<table class="report-table"><thead><tr>';
    html += '<th>Identificador</th><th>Tipo</th><th>Ámbito</th><th>Valor</th><th>Línea</th><th>Columna</th>';
    html += '</tr></thead><tbody>';
    symbols.forEach(sym => {
        html += `<tr>`;
        html += `<td>${sym.id}</td>`;
        html += `<td>${sym.type}</td>`;
        html += `<td>${sym.scope}</td>`;
        html += `<td>${sym.value}</td>`;
        html += `<td>${sym.line}</td>`;
        html += `<td>${sym.col}</td>`;
        html += `</tr>`;
    });
    html += '</tbody></table>';
    symbolsTableContainer.innerHTML = html;
}

// Limpiar reportes
function clearReports() {
    errorsSection.style.display = 'none';
    symbolsSection.style.display = 'none';
    errorsTableContainer.innerHTML = '';
    symbolsTableContainer.innerHTML = '';
    btnDownloadOutput.disabled = true;
    btnDownloadErrors.disabled = true;
    btnDownloadSymbols.disabled = true;
    lastOutput = '';
    lastErrors = [];
    lastSymbols = [];
}

// --- Descargas ---

// Descargar resultado
btnDownloadOutput.addEventListener('click', () => {
    downloadFile('resultado.txt', lastOutput);
});

// Descargar errores
btnDownloadErrors.addEventListener('click', () => {
    let content = '#\tTipo\tDescripción\tLínea\tColumna\n';
    lastErrors.forEach(err => {
        content += `${err.num}\t${err.type}\t${err.description}\t${err.line}\t${err.col}\n`;
    });
    downloadFile('errores.txt', content);
});

// Descargar tabla de símbolos
btnDownloadSymbols.addEventListener('click', () => {
    let content = 'Identificador\tTipo\tÁmbito\tValor\tLínea\tColumna\n';
    lastSymbols.forEach(sym => {
        content += `${sym.id}\t${sym.type}\t${sym.scope}\t${sym.value}\t${sym.line}\t${sym.col}\n`;
    });
    downloadFile('tabla_simbolos.txt', content);
});

// Helper para descargar archivo
function downloadFile(filename, content) {
    const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

// Inicializar
updateLineNumbers();

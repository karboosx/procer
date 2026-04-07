# Procer Syntax Highlighting

The grammar file `procer.tmLanguage.json` provides syntax highlighting for `.procer` / `.prc` files.
It is a standard TextMate grammar and works in both VS Code and PhpStorm.

---

## VS Code

### Option A – install from the folder (developer / local use)

1. Copy the `vscode-extension/` folder to your VS Code extensions directory:
   - **Windows**: `%USERPROFILE%\.vscode\extensions\procer-language-1.0.0\`
   - **macOS/Linux**: `~/.vscode/extensions/procer-language-1.0.0/`
2. Restart VS Code.
3. Open any `.procer` or `.prc` file – it will be highlighted automatically.

### Option B – open as a workspace extension (no copy needed)

1. Open the `vscode-extension/` folder in VS Code.
2. Press **F5** – VS Code launches an Extension Development Host with the grammar loaded.

---

## PhpStorm / IntelliJ IDEA

PhpStorm supports TextMate bundles via the **TextMate Bundles** plugin.

1. Open **Settings → Plugins** and install **TextMate Bundles** (bundled with IntelliJ-based IDEs, may need enabling).
2. Go to **Settings → Editor → TextMate Bundles** and click **+**.
3. Point it at this `syntaxes/` directory (the one containing `procer.tmLanguage.json`).
4. Restart the IDE.
5. Associate `.procer` / `.prc` files with the `Procer` language under
   **Settings → Editor → File Types**.

---

## Highlighted constructs

| Colour group | Examples |
|---|---|
| Keywords (control) | `let`, `if`, `or`, `if not`, `done`, `stop`, `return` |
| Keywords (loop) | `from`, `to`, `by`, `as`, `for each`, `in`, `while` |
| Keywords (signal) | `wait for signal`, `wait for all signals` |
| Keywords (object) | `on`, `do`, `run`, `of` |
| Built-in constants | `true`, `false`, `null` |
| Comparison | `is`, `is not`, `=`, `!=`, `<`, `>`, `<=`, `>=` |
| Logical | `and`, `or` |
| Arithmetic | `+`, `-`, `*`, `/`, `%` |
| Strings | `"Hello, World!"` |
| Numbers | `42`, `3.14` |
| Function definitions | `procedure name(params) do` |
| Function calls | `name(args)` |
| Variables | all other identifiers |
| Comments | `// line comment` |
| Statement terminator | `.` |

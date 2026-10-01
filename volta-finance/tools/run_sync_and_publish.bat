@echo off
rem Launcher for the Windows Scheduled Task "Volta_Finance Sheet Sync". Uses pythonw.exe (the
rem windowless interpreter), not python.exe - python.exe kept dying with exit code 0xC000013A
rem (STATUS_CONTROL_C_EXIT) a few minutes after starting, which is what Windows sends to every
rem process attached to a console when that console closes; pythonw.exe has no console at all, so
rem it cannot receive that signal. Output still goes to sync_and_publish.log via redirection, which
rem works with or without a console window.
setlocal
set PYTHONIOENCODING=utf-8
cd /d "%~dp0"
"C:\Users\Lenovo\AppData\Local\Programs\Python\Python312\pythonw.exe" sync_and_publish.py --watch >> sync_and_publish.log 2>&1
endlocal

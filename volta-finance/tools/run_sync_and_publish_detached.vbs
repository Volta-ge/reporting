' Launches run_sync_and_publish.bat fully detached from any console, so it survives the launching
' console/session closing. The previous direct-.bat Task Scheduler action kept dying with exit code
' 0xC000013A (STATUS_CONTROL_C_EXIT) shortly after being started - it was inheriting a console whose
' Ctrl+Break/close signal broadcast to the whole console process group, killing this process too.
' WScript.Shell.Run with a hidden window style (0) creates the child with no inherited console at all.
Set WshShell = CreateObject("WScript.Shell")
WshShell.Run """C:\Users\Lenovo\Desktop\reporting\volta-finance\tools\run_sync_and_publish.bat""", 0, False

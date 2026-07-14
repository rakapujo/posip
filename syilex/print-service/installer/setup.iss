; POSIP Print Service - Inno Setup Script
; Builds a Windows installer that:
;   - Installs EXE + config to Program Files
;   - Registers as Windows Service (auto-start)
;   - Sets crash recovery policy
;   - Creates desktop shortcut (status check)
;   - Provides clean uninstall via Control Panel

#define MyAppName "POSIP Print Service"
#define MyAppVersion "1.0.0"
#define MyAppPublisher "POSIP"
#define MyAppExeName "posip-print-service.exe"
#define MyServiceName "PosipPrintService"

[Setup]
AppId={{A1B2C3D4-E5F6-7890-ABCD-EF1234567890}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
DefaultDirName={autopf}\POSIP Print Service
DisableProgramGroupPage=yes
OutputDir=output
OutputBaseFilename=posip-print-service-setup
SetupIconFile=..\printer-logo.ico
UninstallDisplayIcon={app}\printer-logo.ico
Compression=lzma
SolidCompression=yes
WizardStyle=modern
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Files]
Source: "..\dist\posip-print-service.exe"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\config.json"; DestDir: "{app}"; Flags: onlyifdoesntexist
Source: "..\printer-logo.ico"; DestDir: "{app}"; Flags: ignoreversion

[Icons]
Name: "{autodesktop}\POSIP Print Service"; Filename: "{app}\{#MyAppExeName}"; Parameters: "status"; IconFilename: "{app}\printer-logo.ico"; Comment: "Check POSIP Print Service status"

[Run]
; Stop existing service if upgrading
Filename: "sc.exe"; Parameters: "stop {#MyServiceName}"; Flags: runhidden waituntilterminated; StatusMsg: "Stopping existing service..."; Check: IsServiceInstalled
; Wait for service to fully stop
Filename: "cmd.exe"; Parameters: "/c timeout /t 2 /nobreak >nul"; Flags: runhidden waituntilterminated; Check: IsServiceInstalled
; Delete existing service if upgrading
Filename: "sc.exe"; Parameters: "delete {#MyServiceName}"; Flags: runhidden waituntilterminated; Check: IsServiceInstalled
; Wait for deletion
Filename: "cmd.exe"; Parameters: "/c timeout /t 1 /nobreak >nul"; Flags: runhidden waituntilterminated; Check: IsServiceInstalled
; Create service
Filename: "sc.exe"; Parameters: "create {#MyServiceName} binPath= ""{app}\{#MyAppExeName} service"" start=auto DisplayName=""{#MyAppName}"""; Flags: runhidden waituntilterminated; StatusMsg: "Installing service..."
; Set description
Filename: "sc.exe"; Parameters: "description {#MyServiceName} ""Thermal printer & cash drawer service for POSIP POS"""; Flags: runhidden waituntilterminated
; Set crash recovery: restart after 5s, 10s, 30s
Filename: "sc.exe"; Parameters: "failure {#MyServiceName} reset=86400 actions=restart/5000/restart/10000/restart/30000"; Flags: runhidden waituntilterminated
; Start service
Filename: "sc.exe"; Parameters: "start {#MyServiceName}"; Flags: runhidden waituntilterminated; StatusMsg: "Starting service..."

[UninstallRun]
; Stop the service
Filename: "sc.exe"; Parameters: "stop {#MyServiceName}"; Flags: runhidden waituntilterminated
; Wait for service to stop
Filename: "cmd.exe"; Parameters: "/c timeout /t 2 /nobreak >nul"; Flags: runhidden waituntilterminated
; Delete the service
Filename: "sc.exe"; Parameters: "delete {#MyServiceName}"; Flags: runhidden waituntilterminated
; Wait for deletion to complete
Filename: "cmd.exe"; Parameters: "/c timeout /t 1 /nobreak >nul"; Flags: runhidden waituntilterminated

[UninstallDelete]
Type: files; Name: "{app}\posip-print.log"
Type: files; Name: "{app}\posip-print.log.1"
Type: files; Name: "{app}\posip-print.log.2"
Type: files; Name: "{app}\posip-print.log.3"

[Code]
function IsServiceInstalled(): Boolean;
var
  ResultCode: Integer;
begin
  Result := Exec('sc.exe', 'query PosipPrintService', '', SW_HIDE, ewWaitUntilTerminated, ResultCode) and (ResultCode = 0);
end;

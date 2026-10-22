; NetWatch Agent - installer Windows (Inno Setup 6.3+).
; Dibangun oleh build-installer.ps1, yang menyiapkan folder build\staging berisi PHP portabel.
;
; Pemasangan interaktif : klik dua kali, isi alamat server dan token.
; Pemasangan senyap     : NetWatchAgent-Setup.exe /VERYSILENT /SUPPRESSMSGBOXES /NORESTART ^
;                           /SERVER=https://netwatch.perusahaan.local /TOKENFILE=\\fileserver\netwatch\token.txt
;   Opsi tambahan: /CAFILE=path\ca.pem  /ALLOWHTTP=1 (hanya uji coba)  /REENROLL=1  /LOG=install.log

#ifndef AppVersion
  #define AppVersion "1.1.0"
#endif

[Setup]
AppId={{B6E1C0A4-3F6B-4C57-9D5E-6C0F2A8E7A11}
AppName=NetWatch Agent
AppVersion={#AppVersion}
AppPublisher=NetWatch
DefaultDirName={autopf}\NetWatch
DisableProgramGroupPage=yes
DisableDirPage=yes
DisableReadyPage=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
OutputDir=..\..\dist
OutputBaseFilename=NetWatchAgent-Setup
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
UninstallDisplayName=NetWatch Agent
CloseApplications=no

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Files]
Source: "..\..\build\staging\php\*"; DestDir: "{app}\runtime\php"; Flags: recursesubdirs ignoreversion
Source: "..\..\build\staging\cacert.pem"; DestDir: "{app}\runtime"; Flags: ignoreversion skipifsourcedoesntexist
Source: "..\..\src\*"; DestDir: "{app}\src"; Flags: recursesubdirs ignoreversion
Source: "..\..\bin\*"; DestDir: "{app}\bin"; Flags: ignoreversion
Source: "..\..\install\windows\register-task.ps1"; DestDir: "{app}\install"; Flags: ignoreversion
Source: "..\..\install\windows\recover-policy.ps1"; DestDir: "{app}\install"; Flags: ignoreversion

[Dirs]
Name: "{commonappdata}\NetWatch"

[INI]
; Shortcut Desktop untuk semua pengguna: membuka form tiket bantuan IT. Nama PC dikirim agar teknisi tahu asal tiket.
Filename: "{commondesktop}\Buat Tiket IT.url"; Section: "InternetShortcut"; Key: "URL"; String: "{code:TicketUrl}"

[UninstallDelete]
Type: files; Name: "{commondesktop}\Buat Tiket IT.url"

[Run]
; Folder data berisi token API perangkat: hanya SYSTEM dan Administrators (SID, agar tidak bergantung bahasa Windows).
Filename: "{sys}\icacls.exe"; Parameters: """{commonappdata}\NetWatch"" /inheritance:r /grant:r *S-1-5-18:(OI)(CI)F *S-1-5-32-544:(OI)(CI)F"; Flags: runhidden

[UninstallRun]
Filename: "{sys}\schtasks.exe"; Parameters: "/Delete /TN NetWatchAgent /F"; Flags: runhidden; RunOnceId: "DeleteTask"

; Folder data (token API) dihapus lewat kode di bawah, hanya bila blokir NetWatch terbukti bersih.

[Code]
var
  ConnectionPage: TInputQueryWizardPage;
  AllowHttpCheck: TNewCheckBox;

function AllowHttpEnabled: Boolean;
begin
  Result := ExpandConstant('{param:ALLOWHTTP|}') <> '';
  if (not Result) and (AllowHttpCheck <> nil) then
    Result := AllowHttpCheck.Checked;
end;

function ConfigPath: String;
begin
  Result := ExpandConstant('{commonappdata}\NetWatch\agent.json');
end;

function NeedsEnrollment: Boolean;
begin
  Result := (not FileExists(ConfigPath)) or (ExpandConstant('{param:REENROLL|}') <> '');
end;

function ReadTokenFromParams: String;
var
  Path, Content: AnsiString;
begin
  Result := '';
  Path := ExpandConstant('{param:TOKENFILE|}');
  if Path <> '' then
  begin
    if LoadStringFromFile(Path, Content) then
      Result := Trim(String(Content));
  end
  else
    Result := Trim(ExpandConstant('{param:TOKEN|}'));
end;

function CurrentServerUrl: String;
begin
  Result := Trim(ExpandConstant('{param:SERVER|}'));
  if (Result = '') and (ConnectionPage <> nil) then
    Result := Trim(ConnectionPage.Values[0]);
end;

function CurrentToken: String;
begin
  Result := ReadTokenFromParams;
  if (Result = '') and (ConnectionPage <> nil) then
    Result := Trim(ConnectionPage.Values[1]);
end;

function TicketUrl(Param: String): String;
begin
  Result := CurrentServerUrl;
  while (Length(Result) > 0) and (Result[Length(Result)] = '/') do
    Delete(Result, Length(Result), 1);
  Result := Result + '/tickets/new?pc=' + GetComputerNameString;
end;

function IsValidServerUrl(const Url: String): Boolean;
begin
  Result := (Pos('https://', LowerCase(Url)) = 1) or
            ((Pos('http://', LowerCase(Url)) = 1) and AllowHttpEnabled);
end;

function IsValidToken(const Token: String): Boolean;
var
  I: Integer;
begin
  Result := Length(Token) = 64;
  if Result then
    for I := 1 to Length(Token) do
      if not (((Token[I] >= '0') and (Token[I] <= '9')) or ((Token[I] >= 'a') and (Token[I] <= 'z')) or
              ((Token[I] >= 'A') and (Token[I] <= 'Z'))) then
      begin
        Result := False;
        Exit;
      end;
end;

function InitializeSetup: Boolean;
begin
  Result := True;
  // Mode senyap tidak punya halaman input, jadi parameter wajib lengkap sejak awal.
  if WizardSilent and NeedsEnrollment then
    if (not IsValidServerUrl(CurrentServerUrl)) or (not IsValidToken(CurrentToken)) then
    begin
      Log('Parameter /SERVER (https://...) dan /TOKENFILE atau /TOKEN (64 karakter) wajib untuk pemasangan senyap.');
      Result := False;
    end;
end;

procedure InitializeWizard;
begin
  ConnectionPage := CreateInputQueryPage(wpWelcome, 'Koneksi ke server NetWatch',
    'Masukkan data dari administrator.', 'Alamat server dan token pendaftaran didapat dari dashboard NetWatch.');
  ConnectionPage.Add('Alamat server (contoh: https://netwatch.perusahaan.local):', False);
  ConnectionPage.Add('Token pendaftaran:', True);

  AllowHttpCheck := TNewCheckBox.Create(ConnectionPage);
  AllowHttpCheck.Parent := ConnectionPage.Surface;
  AllowHttpCheck.Caption := 'Izinkan http:// (khusus uji coba di jaringan tertutup)';
  AllowHttpCheck.Left := 0;
  AllowHttpCheck.Top := ConnectionPage.Edits[1].Top + ConnectionPage.Edits[1].Height + ScaleY(16);
  AllowHttpCheck.Width := ConnectionPage.SurfaceWidth;
end;

function ShouldSkipPage(PageID: Integer): Boolean;
begin
  Result := (ConnectionPage <> nil) and (PageID = ConnectionPage.ID) and (not NeedsEnrollment);
end;

function NextButtonClick(CurPageID: Integer): Boolean;
begin
  Result := True;
  if (ConnectionPage <> nil) and (CurPageID = ConnectionPage.ID) then
  begin
    if not IsValidServerUrl(CurrentServerUrl) then
    begin
      MsgBox('Alamat server harus diawali https:// (untuk uji coba HTTP, centang opsi di bawahnya).', mbError, MB_OK);
      Result := False;
    end
    else if not IsValidToken(CurrentToken) then
    begin
      MsgBox('Token pendaftaran harus 64 karakter huruf/angka.', mbError, MB_OK);
      Result := False;
    end;
  end;
end;

procedure RunOrFail(const FileName, Params, FailMessage: String);
var
  ResultCode: Integer;
begin
  if (not Exec(FileName, Params, '', SW_HIDE, ewWaitUntilTerminated, ResultCode)) or (ResultCode <> 0) then
    RaiseException(FailMessage + ' (kode ' + IntToStr(ResultCode) + '). Lihat ' +
      ExpandConstant('{commonappdata}\NetWatch\agent.log'));
end;

procedure EnrollDevice;
var
  PhpExe, TokenFile, Args, CaFile: String;
begin
  PhpExe := ExpandConstant('{app}\runtime\php\php.exe');
  TokenFile := ExpandConstant('{tmp}\netwatch-token.txt');
  SaveStringToFile(TokenFile, CurrentToken, False);

  Args := '"' + ExpandConstant('{app}\bin\netwatch-agent') + '" enroll "--server=' + CurrentServerUrl +
          '" "--token-file=' + TokenFile + '" "--config=' + ConfigPath + '"';

  CaFile := ExpandConstant('{param:CAFILE|}');
  if CaFile <> '' then
  begin
    if not FileCopy(CaFile, ExpandConstant('{commonappdata}\NetWatch\ca.pem'), False) then
      RaiseException('Berkas CA tidak dapat disalin: ' + CaFile);
    Args := Args + ' "--ca-file=' + ExpandConstant('{commonappdata}\NetWatch\ca.pem') + '"';
  end;

  if AllowHttpEnabled then
    Args := Args + ' --allow-insecure-http';

  try
    RunOrFail(PhpExe, Args, 'Pendaftaran ke server gagal');
  finally
    DeleteFile(TokenFile);
  end;
end;

procedure RegisterTask;
var
  Args: String;
begin
  Args := '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + ExpandConstant('{app}\install\register-task.ps1') +
          '" -PhpExe "' + ExpandConstant('{app}\runtime\php\php.exe') +
          '" -AgentScript "' + ExpandConstant('{app}\bin\netwatch-agent') +
          '" -ConfigFile "' + ConfigPath + '"';
  RunOrFail(ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe'), Args, 'Gagal mendaftarkan tugas latar belakang');
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  ResultCode: Integer;
begin
  if CurStep = ssInstall then
    // Hentikan agent lama (saat upgrade) agar berkasnya tidak terkunci.
    Exec(ExpandConstant('{sys}\schtasks.exe'), '/End /TN NetWatchAgent', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);

  if CurStep = ssPostInstall then
  begin
    if NeedsEnrollment then
      EnrollDevice;
    RegisterTask;
  end;
end;


{ ---- Uninstall: cabut blokir lebih dulu; data hanya dihapus bila pembersihan terbukti berhasil ---- }
var
  PolicyCleanupOk: Boolean;

function RunHidden(const FileName, Params: String): Boolean;
var
  ResultCode: Integer;
begin
  Result := Exec(FileName, Params, '', SW_HIDE, ewWaitUntilTerminated, ResultCode) and (ResultCode = 0);
end;

procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
var
  Php, Agent, Recovery, SafeDir: String;
begin
  if CurUninstallStep = usUninstall then
  begin
    RunHidden(ExpandConstant('{sys}\schtasks.exe'), '/End /TN NetWatchAgent');
    Php := ExpandConstant('{app}\runtime\php\php.exe');
    Agent := ExpandConstant('{app}\bin\netwatch-agent');
    Recovery := ExpandConstant('{app}\install\recover-policy.ps1');

    // 1) lewat agent (tidak butuh agent.json); 2) bila gagal, skrip pemulihan mandiri tanpa PHP.
    PolicyCleanupOk := RunHidden(Php, '"' + Agent + '" clear-policy');
    if not PolicyCleanupOk then
      PolicyCleanupOk := RunHidden(ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe'),
        '-NoProfile -ExecutionPolicy Bypass -File "' + Recovery + '" -Apply');

    if not PolicyCleanupOk then
    begin
      // Simpan skrip pemulihan di luar folder yang akan dihapus.
      SafeDir := ExpandConstant('{commonappdata}\NetWatch-Recovery');
      ForceDirectories(SafeDir);
      FileCopy(Recovery, SafeDir + '\recover-policy.ps1', False);
      SaveStringToFile(SafeDir + '\BACA-SAYA.txt',
        'Blokir NetWatch (hosts/firewall/kebijakan Chrome-Edge) belum bisa dibersihkan otomatis saat uninstall.' + #13#10 +
        'Buka PowerShell sebagai Administrator, periksa dulu: .\recover-policy.ps1' + #13#10 +
        'Lalu bersihkan: .\recover-policy.ps1 -Apply' + #13#10, False);
      if not UninstallSilent then
        MsgBox('Blokir NetWatch belum bisa dibersihkan otomatis. Petunjuk dan skrip pemulihan ada di ' + SafeDir + '.', mbError, MB_OK);
    end;
  end
  else if CurUninstallStep = usPostUninstall then
  begin
    // Data agent (termasuk log) dipertahankan bila pembersihan gagal agar diagnosis dan pemulihan tetap mungkin.
    if PolicyCleanupOk then
      DelTree(ExpandConstant('{commonappdata}\NetWatch'), True, True, True);
  end;
end;

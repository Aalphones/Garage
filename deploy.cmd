@echo off
rem ==========================================================================
rem  GARAGE - hochladen per Doppelklick (ADR-003).
rem    deploy.cmd     Code nach www/garage-app/, Bruecke und Oberflaeche nach www/garage/,
rem                   danach Migrationen und Rauchtest.
rem  Die Wurzel-.htaccess der Domain gehoert DISPATCH und wird hier nie hochgeladen.
rem ==========================================================================
setlocal
chcp 65001 >nul
title GARAGE Deploy
cd /d "%~dp0"

if exist "deploy.env" goto :configFound
echo [FEHLER] Die Datei deploy.env fehlt.
echo          Kopiere deploy.env.example nach deploy.env und trage deine
echo          Zugangsdaten ein.
goto :fail

:configFound
rem Einlesen bewusst ohne "delayed expansion": die wuerde ein Ausrufezeichen
rem im Passwort verschlucken. Ab dem setlocal darunter ist sie an, damit
rem Werte mit & | < > beim Schreiben nicht als Befehle gelesen werden.
for /f "usebackq eol=# tokens=1,* delims==" %%A in ("deploy.env") do set "%%A=%%B"

setlocal enabledelayedexpansion

if not defined REMOTE_WEB_ROOT set "REMOTE_WEB_ROOT=/"

echo [1/7] Konfiguration pruefen ...
set "MISSING="
call :needValue WINSCP_PATH
call :needValue SFTP_PROTOCOL
call :needValue SFTP_HOST
call :needValue SFTP_USER
call :needValue SFTP_PASSWORD
call :needValue PUBLIC_BASE_URL
call :needValue NEIGHBOR_URLS
call :needValue GARAGE_DB_HOST
call :needValue GARAGE_DB_NAME
call :needValue GARAGE_DB_USER
call :needValue GARAGE_DB_PASSWORD
call :needValue GARAGE_DEPLOY_TOKEN
call :needValue GARAGE_JWT_SECRET
if defined MISSING goto :fail

rem Der Token geht in einen HTTP-Header und in die .env: nur Buchstaben und Ziffern,
rem damit weder die Kommandozeile noch der Header ihn verstuemmeln.
echo(!GARAGE_DEPLOY_TOKEN!| findstr /r /x "[A-Za-z0-9][A-Za-z0-9]*" >nul
if errorlevel 1 (
    echo [FEHLER] GARAGE_DEPLOY_TOKEN darf nur Buchstaben und Ziffern enthalten.
    echo          Erzeugen: php -r "echo bin2hex(random_bytes(32));"
    goto :fail
)

rem Der JWT-Schluessel kommt in die .env: gleiche Zeichenregel, mindestens 64 Zeichen.
echo(!GARAGE_JWT_SECRET!| findstr /r /x "[A-Za-z0-9][A-Za-z0-9]*" >nul
if errorlevel 1 (
    echo [FEHLER] GARAGE_JWT_SECRET darf nur Buchstaben und Ziffern enthalten.
    goto :fail
)
if "!GARAGE_JWT_SECRET:~63,1!"=="" (
    echo [FEHLER] GARAGE_JWT_SECRET braucht mindestens 64 Zeichen, empfohlen sind 96.
    goto :fail
)

rem Zielpfade und Adresse einheitlich: Ordner enden auf /, die Adresse nicht.
if not "!REMOTE_WEB_ROOT:~-1!"=="/" set "REMOTE_WEB_ROOT=!REMOTE_WEB_ROOT!/"
if "!PUBLIC_BASE_URL:~-1!"=="/" set "PUBLIC_BASE_URL=!PUBLIC_BASE_URL:~0,-1!"

where curl >nul 2>&1
if errorlevel 1 (
    echo [FEHLER] curl wurde nicht gefunden. Es gehoert ab Windows 10 zum System.
    goto :fail
)

if exist "!WINSCP_PATH!" goto :winscpFound
echo [FEHLER] WinSCP wurde nicht gefunden:
echo          !WINSCP_PATH!
echo          Gebraucht wird WinSCP.com aus dem portablen Paket, nicht WinSCP.exe.
goto :fail

:winscpFound
if /i not "!SFTP_PROTOCOL!"=="sftp" goto :protocolOk
if not defined SFTP_HOSTKEY (
    echo [FEHLER] SFTP_HOSTKEY fehlt in deploy.env.
    echo          WinSCP einmal von Hand starten, verbinden, den angezeigten
    echo          Fingerabdruck in deploy.env eintragen.
    goto :fail
)
if "!SFTP_HOSTKEY!"=="*" (
    echo [FEHLER] SFTP_HOSTKEY darf nicht * sein: der Stern akzeptiert jeden Server,
    echo          der sich fuer deinen ausgibt. Den echten Fingerabdruck eintragen.
    goto :fail
)

:protocolOk
echo [2/7] Lokal pruefen ...
set "LINT_FAILED="
if not defined PHP_PATH goto :lintSkipped
if not exist "!PHP_PATH!" goto :lintSkipped
for /r "app" %%F in (*.php) do call :lintFile "%%F"
for /r "web" %%F in (*.php) do call :lintFile "%%F"
if exist "bin" for /r "bin" %%F in (*) do call :lintFile "%%F"
if not defined LINT_FAILED goto :lintDone
echo [FEHLER] Syntaxfehler gefunden. Es wird nichts hochgeladen.
goto :fail

:lintSkipped
echo          PHP_PATH fehlt oder ist ungueltig - die Syntaxpruefung entfaellt.

:lintDone
rem Werte in einfache Anfuehrungszeichen: der .env-Leser schneidet einen
rem unquotierten Wert am ersten Rautezeichen ab - ein Passwort mit # kaeme
rem verstuemmelt an.
echo [3/7] app\.env schreiben ...
> "app\.env" echo GARAGE_BASE_URL='!PUBLIC_BASE_URL!'
>>"app\.env" echo GARAGE_DB_HOST='!GARAGE_DB_HOST!'
>>"app\.env" echo GARAGE_DB_NAME='!GARAGE_DB_NAME!'
>>"app\.env" echo GARAGE_DB_USER='!GARAGE_DB_USER!'
>>"app\.env" echo GARAGE_DB_PASSWORD='!GARAGE_DB_PASSWORD!'
>>"app\.env" echo GARAGE_DEPLOY_TOKEN='!GARAGE_DEPLOY_TOKEN!'
>>"app\.env" echo GARAGE_JWT_SECRET='!GARAGE_JWT_SECRET!'

rem Vorlauf: WinSCP legt Zielordner beim Abgleich nicht selbst an. Existieren sie
rem schon, meldet mkdir einen Fehler - deshalb laeuft das getrennt und ungeprueft.
echo [4/7] Zielordner anlegen ...
set "PREP_SCRIPT=%TEMP%\garage-prep-%RANDOM%%RANDOM%.txt"
call :writeSession "!PREP_SCRIPT!"
>>"!PREP_SCRIPT!" echo option batch continue
>>"!PREP_SCRIPT!" echo mkdir "!REMOTE_WEB_ROOT!garage"
>>"!PREP_SCRIPT!" echo mkdir "!REMOTE_WEB_ROOT!garage-app"
>>"!PREP_SCRIPT!" echo mkdir "!REMOTE_WEB_ROOT!garage-app/storage"
>>"!PREP_SCRIPT!" echo mkdir "!REMOTE_WEB_ROOT!garage-app/storage/logs"
>>"!PREP_SCRIPT!" echo mkdir "!REMOTE_WEB_ROOT!garage-app/storage/documents"
>>"!PREP_SCRIPT!" echo exit
"!WINSCP_PATH!" /ini=nul /script="!PREP_SCRIPT!" >nul 2>&1
del "!PREP_SCRIPT!" >nul 2>&1

rem Abgleich mit Loeschen nur in den eigenen Unterordnern, nie auf die Wurzel:
rem dort liegen DISPATCH, CardMaker und CamScanner (ADR-001).
set "WINSCP_SCRIPT=%TEMP%\garage-deploy-%RANDOM%%RANDOM%.txt"
call :writeSession "!WINSCP_SCRIPT!"
>>"!WINSCP_SCRIPT!" echo synchronize remote -delete -filemask="|storage/;.env.local" "app" "!REMOTE_WEB_ROOT!garage-app/"
>>"!WINSCP_SCRIPT!" echo synchronize remote -delete "web" "!REMOTE_WEB_ROOT!garage/"
>>"!WINSCP_SCRIPT!" echo close
>>"!WINSCP_SCRIPT!" echo exit

echo [5/7] Verbinden und hochladen ...
"!WINSCP_PATH!" /ini=nul /script="!WINSCP_SCRIPT!"
set "WINSCP_EXIT=!ERRORLEVEL!"
del "!WINSCP_SCRIPT!" >nul 2>&1
if "!WINSCP_EXIT!"=="0" goto :uploadDone
echo.
echo [FEHLER] WinSCP hat abgebrochen, Code !WINSCP_EXIT!.
echo          Der Server ist eventuell nur halb aktualisiert.
echo          Meist liegt es am Passwort, am Fingerabdruck oder an REMOTE_WEB_ROOT.
goto :fail

:uploadDone
echo [6/7] Migrationen anstossen ...
set "DEPLOY_BODY=%TEMP%\garage-deploy-%RANDOM%.json"
set "DEPLOY_STATUS=000"
for /f %%S in ('curl -s --max-time 120 -o "!DEPLOY_BODY!" -w "%%{http_code}" -X POST -H "Authorization: Bearer !GARAGE_DEPLOY_TOKEN!" "!PUBLIC_BASE_URL!/garage/api/deploy"') do set "DEPLOY_STATUS=%%S"
if "!DEPLOY_STATUS!"=="200" (
    type "!DEPLOY_BODY!"
    echo.
    del "!DEPLOY_BODY!" >nul 2>&1
    goto :smokeTest
)
echo [FEHLER] Der Deploy-Endpunkt antwortete mit Status !DEPLOY_STATUS! statt 200.
if exist "!DEPLOY_BODY!" (
    type "!DEPLOY_BODY!"
    echo.
)
del "!DEPLOY_BODY!" >nul 2>&1
if "!DEPLOY_STATUS!"=="401" (
    echo          Kommt der Authorization-Header nicht an? Mit X-Garage-Token gegenpruefen.
)
echo          Der Code liegt oben, aber die Migrationen sind nicht gelaufen.
goto :fail

:smokeTest
echo [7/7] Rauchtest ...
set "SMOKE_FAILS=0"
call :expect /garage/ 200
call :expect /garage/api/health 200
call :expect /garage-app/.env 403
call :expect /garage-app/config.php 403
for %%U in (!NEIGHBOR_URLS!) do call :expect "%%~U" 200
if "!SMOKE_FAILS!"=="0" goto :done
echo.
echo [FEHLER] Rauchtest: !SMOKE_FAILS! Fehlschlaege - der Server ist eventuell nur halb aktualisiert.
goto :fail

:done
echo.
echo Fertig, der Rauchtest ist gruen.
echo          Code         nach !REMOTE_WEB_ROOT!garage-app/
echo          Oberflaeche  nach !REMOTE_WEB_ROOT!garage/
echo.
pause
exit /b 0

:fail
echo.
pause
exit /b 1

rem Prueft eine Adresse gegen den erwarteten Status und meldet einen Fehlschlag
rem einzeln. Kein -L: eine Weiterleitung ist hier ein Fehler, kein Erfolg.
:expect
set "EXPECT_URL=!PUBLIC_BASE_URL!%~1"
set "ACTUAL_STATUS=000"
for /f %%S in ('curl -s --max-time 30 -o NUL -w "%%{http_code}" "!EXPECT_URL!"') do set "ACTUAL_STATUS=%%S"
if "!ACTUAL_STATUS!"=="%~2" (
    echo          ok   %~2  %~1
    exit /b 0
)
echo          FEHL !ACTUAL_STATUS! statt %~2  %~1
set /a SMOKE_FAILS+=1
exit /b 0

:lintFile
"!PHP_PATH!" -l %1 >nul 2>&1
if errorlevel 1 (
    echo          Syntaxfehler: %~1
    set "LINT_FAILED=1"
)
exit /b 0

rem Zugangsdaten bewusst als eigene Schalter, nicht in der Adresse: ein # oder /
rem im Passwort wuerde die Adresse zerschneiden, ein | sogar die Skriptzeile.
:writeSession
> "%~1" echo option batch abort
>>"%~1" echo option confirm off
>>"%~1" echo option transfer binary
if /i "!SFTP_PROTOCOL!"=="sftp" goto :writeSessionSftp
>>"%~1" echo open !SFTP_PROTOCOL!://!SFTP_HOST!/ -username="!SFTP_USER!" -password="!SFTP_PASSWORD!"
exit /b 0
:writeSessionSftp
>>"%~1" echo open sftp://!SFTP_HOST!/ -username="!SFTP_USER!" -password="!SFTP_PASSWORD!" -hostkey="!SFTP_HOSTKEY!"
exit /b 0

:needValue
if not defined %~1 (
    echo [FEHLER] In deploy.env fehlt ein Wert: %~1
    set "MISSING=1"
)
exit /b 0

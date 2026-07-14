@echo off
echo ============================================
echo   POSIP Print Service - Build Installer
echo ============================================
echo.

:: Check Python
python --version >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Python not found. Install Python 3.10+ first.
    pause
    exit /b 1
)

:: Check Inno Setup
if not exist "C:\Program Files (x86)\Inno Setup 6\ISCC.exe" (
    echo [ERROR] Inno Setup 6 not found.
    echo Download from: https://jrsoftware.org/isdl.php
    pause
    exit /b 1
)

:: Convert PNG to ICO if needed
if not exist "printer-logo.ico" (
    echo [1/4] Converting icon...
    python -c "from PIL import Image; img=Image.open('printer-logo.png').convert('RGBA'); sizes=[(16,16),(32,32),(48,48),(64,64),(128,128),(256,256)]; imgs=[img.resize(s,Image.LANCZOS) for s in sizes]; imgs[0].save('printer-logo.ico',format='ICO',sizes=[(i.width,i.height) for i in imgs],append_images=imgs[1:])"
    if errorlevel 1 (
        echo [ERROR] Icon conversion failed. Install Pillow: pip install Pillow
        pause
        exit /b 1
    )
) else (
    echo [1/4] Icon already exists, skipping...
)

:: Install dependencies
echo [2/4] Installing dependencies...
pip install -r requirements.txt >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Failed to install dependencies.
    pause
    exit /b 1
)

:: Build EXE (noconsole for service mode)
echo [3/4] Building EXE with PyInstaller...
pyinstaller ^
    --onefile ^
    --name posip-print-service ^
    --icon printer-logo.ico ^
    --noconsole ^
    --hidden-import=uvicorn.logging ^
    --hidden-import=uvicorn.loops ^
    --hidden-import=uvicorn.loops.auto ^
    --hidden-import=uvicorn.protocols ^
    --hidden-import=uvicorn.protocols.http ^
    --hidden-import=uvicorn.protocols.http.auto ^
    --hidden-import=uvicorn.protocols.websockets ^
    --hidden-import=uvicorn.protocols.websockets.auto ^
    --hidden-import=uvicorn.lifespan ^
    --hidden-import=uvicorn.lifespan.on ^
    --hidden-import=uvicorn.lifespan.off ^
    --hidden-import=email.mime.multipart ^
    --hidden-import=email.mime.text ^
    --hidden-import=win32serviceutil ^
    --hidden-import=win32service ^
    --hidden-import=win32event ^
    --hidden-import=servicemanager ^
    --hidden-import=win32api ^
    --collect-submodules=escpos ^
    --collect-submodules=usb ^
    run.py

if errorlevel 1 (
    echo [ERROR] PyInstaller build failed.
    pause
    exit /b 1
)

:: Build installer with Inno Setup
echo [4/4] Building Windows installer...
"C:\Program Files (x86)\Inno Setup 6\ISCC.exe" installer\setup.iss

if errorlevel 1 (
    echo [ERROR] Inno Setup build failed.
    pause
    exit /b 1
)

:: Copy installer to public/downloads
echo [5/5] Copying installer to public/downloads...
copy /Y "installer\output\posip-print-service-setup.exe" "..\public\downloads\posip-print-service-setup.exe" >nul

echo.
echo ============================================
echo   BUILD COMPLETE!
echo ============================================
echo.
echo   Installer: installer\output\posip-print-service-setup.exe
echo   Copied to: ..\public\downloads\posip-print-service-setup.exe
echo.
echo   Deploy ke PC kasir:
echo     1. Copy installer ke PC kasir
echo     2. Double-click, Next - Next - Install - Finish
echo     3. Service otomatis berjalan dan auto-start
echo.
echo   Uninstall: Control Panel ^> Apps ^> POSIP Print Service
echo ============================================
pause

"""
POSIP Print Service - Thermal printer & cash drawer thin proxy for POSIP POS.

Receives raw ESC/POS bytes from the frontend (base64-encoded) and forwards them
to the printer via Windows/USB/Network transport. All receipt formatting is done
in the browser (useReceiptEscPos.js).

Usage:
  posip-print-service.exe                  Run in console (for testing)
  posip-print-service.exe service          Run as Windows Service (called by SCM)
  posip-print-service.exe status           Check service status
"""

import os
import sys
import json
import socket
import base64
import logging
import logging.handlers
import threading
from contextlib import asynccontextmanager

# Early crash capture — before anything else can fail
def _early_debug(msg):
    try:
        d = os.path.join(os.environ.get("APPDATA", "."), "POSIP Print Service")
        os.makedirs(d, exist_ok=True)
        with open(os.path.join(d, "debug.log"), "a", encoding="utf-8") as f:
            import datetime
            f.write(f"{datetime.datetime.now()} [EARLY] {msg}\n")
    except Exception:
        pass

_early_debug(f"Module loading: argv={sys.argv}, stdout={sys.stdout is not None}")

# ==================== Path Setup ====================
if getattr(sys, 'frozen', False):
    BASE_DIR = os.path.dirname(sys.executable)
else:
    BASE_DIR = os.path.dirname(os.path.abspath(__file__))

CONFIG_FILE = os.path.join(BASE_DIR, "config.json")
VERSION = "2.0.0"

# Log file: try BASE_DIR first, fallback to %APPDATA% if permission denied
def _resolve_log_file():
    primary = os.path.join(BASE_DIR, "posip-print.log")
    try:
        with open(primary, "a", encoding="utf-8"):
            pass
        return primary
    except PermissionError:
        fallback_dir = os.path.join(os.environ.get("APPDATA", BASE_DIR), "POSIP Print Service")
        os.makedirs(fallback_dir, exist_ok=True)
        return os.path.join(fallback_dir, "posip-print.log")

LOG_FILE = _resolve_log_file()


def _debug_log(msg):
    """Emergency debug log — writes even when logging isn't set up yet."""
    try:
        debug_file = os.path.join(os.environ.get("APPDATA", BASE_DIR), "POSIP Print Service", "debug.log")
        os.makedirs(os.path.dirname(debug_file), exist_ok=True)
        with open(debug_file, "a", encoding="utf-8") as f:
            import datetime
            f.write(f"{datetime.datetime.now()} {msg}\n")
    except Exception:
        pass


# ==================== Logging ====================
_log_handlers = [
    logging.handlers.RotatingFileHandler(
        LOG_FILE, maxBytes=5*1024*1024, backupCount=3, encoding="utf-8"
    ),
]
if sys.stdout is not None:
    _log_handlers.insert(0, logging.StreamHandler(sys.stdout))

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    handlers=_log_handlers,
)
logger = logging.getLogger("posip-print")

# ==================== Config ====================
DEFAULT_CONFIG = {
    "port": 5123,
    "host": "127.0.0.1",
    "printers": {
        "network": []
    },
}


def load_config():
    if os.path.exists(CONFIG_FILE):
        try:
            with open(CONFIG_FILE, "r", encoding="utf-8") as f:
                cfg = json.load(f)
        except json.JSONDecodeError as e:
            logger.error(f"Config syntax error: {e}. Using defaults.")
            return DEFAULT_CONFIG.copy()
        for k, v in DEFAULT_CONFIG.items():
            if k not in cfg:
                cfg[k] = v
            elif k == "printers" and isinstance(v, dict):
                for pk, pv in v.items():
                    if pk not in cfg[k]:
                        cfg[k][pk] = pv
        return cfg
    else:
        try:
            with open(CONFIG_FILE, "w", encoding="utf-8") as f:
                json.dump(DEFAULT_CONFIG, f, indent=4, ensure_ascii=False)
            logger.info(f"Created default config: {CONFIG_FILE}")
        except PermissionError:
            logger.warning(f"Cannot write config to {CONFIG_FILE}. Using defaults.")
        return DEFAULT_CONFIG.copy()


# ==================== ESC/POS (drawer only) ====================
ESC_INIT = b'\x1b\x40'
ESC_OPEN_DRAWER_PIN2 = b'\x1b\x70\x00\x19\x19'


# ==================== Printer Discovery ====================

def discover_windows_printers():
    printers = []
    try:
        import win32print
        flags = win32print.PRINTER_ENUM_LOCAL | win32print.PRINTER_ENUM_CONNECTIONS
        for _, _, name, _ in win32print.EnumPrinters(flags):
            printers.append({"type": "windows", "name": name, "id": f"WIN:{name}"})
    except Exception as e:
        logger.warning(f"Windows printer discovery failed: {e}")
    return printers


def discover_usb_printers():
    printers = []
    try:
        import usb.core
        devices = usb.core.find(find_all=True)
        if devices is None:
            return printers
        for dev in devices:
            try:
                for cfg in dev:
                    for intf in cfg:
                        if intf.bInterfaceClass == 7:
                            name = ""
                            try:
                                name = dev.product or ""
                            except Exception:
                                pass
                            if not name:
                                name = f"USB {dev.idVendor:04x}:{dev.idProduct:04x}"
                            printers.append({
                                "type": "usb", "name": str(name),
                                "vendor_id": f"0x{dev.idVendor:04x}",
                                "product_id": f"0x{dev.idProduct:04x}",
                                "id": f"USB:{dev.idVendor:04x}:{dev.idProduct:04x}"
                            })
                            break
            except Exception:
                pass
    except Exception as e:
        logger.warning(f"USB discovery failed: {e}")
    return printers


def get_network_printers(config):
    printers = []
    for np in config.get("printers", {}).get("network", []):
        ip = np.get("ip", "")
        port = np.get("port", 9100)
        printers.append({
            "type": "network",
            "name": np.get("name", f"{ip}:{port}"),
            "ip": ip, "port": port,
            "id": f"NET:{ip}:{port}"
        })
    return printers


# ==================== Printer Transport ====================

def send_to_windows_printer(printer_name, data):
    import win32print
    handle = win32print.OpenPrinter(printer_name)
    try:
        win32print.StartDocPrinter(handle, 1, ("POSIP Receipt", None, "RAW"))
        win32print.StartPagePrinter(handle)
        win32print.WritePrinter(handle, data)
        win32print.EndPagePrinter(handle)
        win32print.EndDocPrinter(handle)
    finally:
        win32print.ClosePrinter(handle)


def send_to_network(ip, port, data):
    sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    sock.settimeout(5)
    try:
        sock.connect((ip, port))
        sock.sendall(data)
    finally:
        sock.close()


def send_to_usb(vendor_id, product_id, data):
    from escpos.printer import Usb
    vid = int(vendor_id, 16) if isinstance(vendor_id, str) else vendor_id
    pid = int(product_id, 16) if isinstance(product_id, str) else product_id
    p = Usb(vid, pid)
    p._raw(data)
    p.close()


def send_to_printer(printer_id, data, config):
    if not printer_id or not isinstance(printer_id, str):
        raise ValueError("Printer ID is required")
    if printer_id.startswith("WIN:"):
        printer_name = printer_id[4:]
        if not printer_name:
            raise ValueError("Invalid Windows printer name")
        send_to_windows_printer(printer_name, data)
    elif printer_id.startswith("NET:"):
        parts = printer_id.split(":")
        if len(parts) != 3:
            raise ValueError("Invalid network printer format. Expected NET:IP:PORT")
        try:
            port = int(parts[2])
        except (ValueError, IndexError):
            raise ValueError(f"Invalid port: {parts[2]}")
        if not (1 <= port <= 65535):
            raise ValueError(f"Port must be 1-65535, got {port}")
        send_to_network(parts[1], port, data)
    elif printer_id.startswith("USB:"):
        parts = printer_id.split(":")
        if len(parts) != 3:
            raise ValueError("Invalid USB printer format. Expected USB:VID:PID")
        send_to_usb(parts[1], parts[2], data)
    else:
        raise ValueError(f"Unknown printer format: {printer_id}. Use WIN:, NET:, or USB: prefix")


def open_cash_drawer(printer_id, config):
    send_to_printer(printer_id, ESC_INIT + ESC_OPEN_DRAWER_PIN2, config)


# ==================== FastAPI App ====================
from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from pydantic import BaseModel

_initial_config = load_config()
config = {}


@asynccontextmanager
async def lifespan(app: FastAPI):
    global config
    config = load_config()
    logger.info(f"POSIP Print Service v{VERSION}")
    logger.info(f"Listening on http://{config['host']}:{config['port']}")
    logger.info(f"Config: {CONFIG_FILE}")
    yield
    logger.info("Shutting down...")


app = FastAPI(title="POSIP Print Service", version=VERSION, lifespan=lifespan)
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["GET", "POST"],
    allow_headers=["Content-Type"],
)


# ─── Request Models ───

class PrintRawRequest(BaseModel):
    printer: str
    data: str  # base64-encoded ESC/POS bytes
    open_drawer: bool = False

class PrinterRequest(BaseModel):
    printer: str


# ─── Endpoints ───

@app.get("/status")
async def status():
    return {"status": "ok", "version": VERSION}


@app.get("/printers")
async def list_printers():
    win = discover_windows_printers()
    usb = discover_usb_printers()
    network = get_network_printers(config)
    return {"windows": win, "usb": usb, "network": network, "all": win + usb + network}


@app.post("/print/raw")
async def api_print_raw(req: PrintRawRequest):
    """Receive base64-encoded ESC/POS bytes from frontend, forward to printer."""
    try:
        raw_bytes = base64.b64decode(req.data)
        if req.open_drawer:
            raw_bytes = ESC_OPEN_DRAWER_PIN2 + raw_bytes
        send_to_printer(req.printer, raw_bytes, config)
        logger.info(f"Raw print to {req.printer} ({len(raw_bytes)} bytes)")
        return {"success": True, "message": "Printed"}
    except ValueError as e:
        logger.warning(f"Print validation error: {e}")
        return JSONResponse(status_code=400, content={"success": False, "message": str(e)})
    except Exception as e:
        logger.error(f"Print failed: {e}")
        return JSONResponse(status_code=500, content={"success": False, "message": "Gagal mencetak. Periksa koneksi printer."})


@app.post("/drawer/open")
async def api_drawer_open(req: PrinterRequest):
    try:
        open_cash_drawer(req.printer, config)
        logger.info(f"Cash drawer opened via {req.printer}")
        return {"success": True, "message": "Cash drawer opened"}
    except ValueError as e:
        logger.warning(f"Drawer validation error: {e}")
        return JSONResponse(status_code=400, content={"success": False, "message": str(e)})
    except Exception as e:
        logger.error(f"Open drawer failed: {e}")
        return JSONResponse(status_code=500, content={"success": False, "message": "Gagal membuka laci kas. Periksa koneksi printer."})


# ==================== Uvicorn Server ====================

def run_server():
    import uvicorn
    cfg = load_config()
    log_config = uvicorn.config.LOGGING_CONFIG if sys.stdout is not None else None
    uv_config = uvicorn.Config(
        app, host=cfg["host"], port=cfg["port"],
        log_level="info", log_config=log_config
    )
    return uvicorn.Server(uv_config)


# ==================== Windows Service ====================

class PosipPrintService:
    _svc_name_ = "PosipPrintService"
    _svc_display_name_ = "POSIP Print Service"
    _svc_description_ = "Thermal printer & cash drawer service for POSIP POS"

    @staticmethod
    def status():
        import subprocess
        result = subprocess.run(["sc", "query", "PosipPrintService"], capture_output=True, text=True)
        if result.returncode == 0:
            output = result.stdout
            if "RUNNING" in output:
                print("[OK] Service is RUNNING")
            elif "STOPPED" in output:
                print("[--] Service is STOPPED")
            elif "PENDING" in output:
                print("[..] Service is PENDING")
            else:
                print(output)
        else:
            print("[--] Service is NOT INSTALLED")

    @staticmethod
    def run_as_service():
        try:
            import win32serviceutil
            import win32service
            import win32event
            import servicemanager
        except ImportError as e:
            _debug_log(f"Import error: {e}")
            raise

        class _Svc(win32serviceutil.ServiceFramework):
            _svc_name_ = "PosipPrintService"
            _svc_display_name_ = "POSIP Print Service"

            def __init__(self, args):
                win32serviceutil.ServiceFramework.__init__(self, args)
                self.stop_event = win32event.CreateEvent(None, 0, 0, None)
                self._server = None

            def SvcStop(self):
                self.ReportServiceStatus(win32service.SERVICE_STOP_PENDING)
                if self._server:
                    self._server.should_exit = True
                win32event.SetEvent(self.stop_event)

            def SvcDoRun(self):
                try:
                    servicemanager.LogMsg(
                        servicemanager.EVENTLOG_INFORMATION_TYPE,
                        servicemanager.PYS_SERVICE_STARTED,
                        (self._svc_name_, "")
                    )
                    self._server = run_server()
                    thread = threading.Thread(target=self._server.run, daemon=True)
                    thread.start()
                    win32event.WaitForSingleObject(self.stop_event, win32event.INFINITE)
                    self._server.should_exit = True
                    thread.join(timeout=5)
                except Exception as e:
                    _debug_log(f"SvcDoRun error: {e}")
                    raise

        try:
            sys.argv = [sys.argv[0]]
            servicemanager.Initialize()
            servicemanager.PrepareToHostSingle(_Svc)
            servicemanager.StartServiceCtrlDispatcher()
        except Exception as e:
            _debug_log(f"Service init error: {e}")
            raise


# ==================== Main ====================

def main():
    if len(sys.argv) > 1:
        cmd = sys.argv[1].lower()
        if cmd == "service":
            PosipPrintService.run_as_service()
            return
        elif cmd == "status":
            PosipPrintService.status()
            return
        elif cmd in ("--help", "-h", "help"):
            print()
            print(f"POSIP Print Service v{VERSION}")
            print()
            print("Usage:")
            print("  posip-print-service.exe              Run in console mode")
            print("  posip-print-service.exe service       Run as Windows Service (SCM)")
            print("  posip-print-service.exe status        Check service status")
            print()
            print("Config:  config.json (same folder as exe)")
            print("Log:     posip-print.log")
            print()
            print("Install/uninstall: Use the POSIP Print Service installer.")
            return

    import uvicorn
    cfg = load_config()
    host = cfg["host"]
    port = cfg["port"]

    print()
    print("=" * 50)
    print(f"  POSIP Print Service v{VERSION}")
    print("=" * 50)
    print(f"  URL     : http://{host}:{port}")
    print(f"  Config  : {CONFIG_FILE}")
    print(f"  Log     : {LOG_FILE}")
    print("=" * 50)
    print("  Press Ctrl+C to stop")
    print("=" * 50)
    print()

    uvicorn.run(app, host=host, port=port, log_level="info", log_config=None)


if __name__ == "__main__":
    try:
        _debug_log(f"Starting: argv={sys.argv}, frozen={getattr(sys, 'frozen', False)}, BASE_DIR={BASE_DIR}")
        main()
    except Exception as e:
        _debug_log(f"Fatal: {type(e).__name__}: {e}")
        import traceback
        _debug_log(traceback.format_exc())

/**
 * Browser ESC/POS transport — core logic (testable with injected navigator).
 * Ported from POSTITIK transport.ts.
 */

/** @typedef {'bluetooth' | 'serial' | 'usb'} PrinterKind */

/**
 * @typedef {Object} PrinterConnection
 * @property {PrinterKind} kind
 * @property {string} label
 * @property {(data: Uint8Array) => Promise<void>} write
 * @property {() => Promise<void>} disconnect
 */

/** @type {PrinterConnection | null} */
let active = null;

const BT_SERVICES = [
    0x18f0,
    0xffe0,
    0xff00,
    '49535343-fe7d-4ae5-8fa9-9fafd205e455',
    'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
    '6e400001-b5a3-f393-e0a9-e50e24dcca9e'
];

/**
 * @param {Navigator} [nav]
 */
function navDevices(nav = typeof navigator !== 'undefined' ? navigator : /** @type {Navigator} */ ({})) {
    return nav;
}

export function getActiveConnection() {
    return active;
}

export function setActiveConnection(conn) {
    active = conn;
}

export function clearActiveConnection() {
    active = null;
}

/**
 * @param {Navigator} [nav]
 */
export function supportMatrix(nav) {
    const n = navDevices(nav);
    return {
        bluetooth: !!n.bluetooth,
        serial: !!n.serial,
        usb: !!n.usb
    };
}

/**
 * @param {Navigator} [nav]
 */
export function isThermalSupported(nav) {
    const m = supportMatrix(nav);
    return m.bluetooth || m.serial || m.usb;
}

/**
 * @param {import('./printStorage.js').StoredPrinter | null} stored
 * @param {() => void} onForget
 */
export function forgetPrinter(stored, onForget) {
    onForget?.();
    if (active) {
        active.disconnect().catch(() => {});
        active = null;
    }
}

/**
 * @param {BluetoothDeviceLike} device
 * @returns {Promise<PrinterConnection>}
 */
async function bluetoothConnFromDevice(device) {
    const server = await device.gatt.connect();
    const services = await server.getPrimaryServices();
    /** @type {BluetoothCharacteristicLike | null} */
    let target = null;
    for (const s of services) {
        const chars = await s.getCharacteristics();
        for (const c of chars) {
            if (c.properties.write || c.properties.writeWithoutResponse) {
                target = c;
                break;
            }
        }
        if (target) break;
    }
    if (!target) throw new Error('Karakteristik tulis printer Bluetooth tidak ditemukan.');
    const noResp = target.properties.writeWithoutResponse && !target.properties.write;
    const writeChar = target;
    return {
        kind: 'bluetooth',
        label: device.name || 'Printer Bluetooth',
        async write(data) {
            const CHUNK = 180;
            for (let i = 0; i < data.length; i += CHUNK) {
                const slice = data.slice(i, i + CHUNK);
                if (noResp && writeChar.writeValueWithoutResponse) {
                    await writeChar.writeValueWithoutResponse(slice);
                } else {
                    await writeChar.writeValue(slice);
                }
                await new Promise((r) => setTimeout(r, 18));
            }
        },
        async disconnect() {
            try {
                device.gatt?.disconnect();
            } catch {
                /* abaikan */
            }
        }
    };
}

/**
 * @param {SerialPortLike} port
 * @returns {PrinterConnection}
 */
function serialConnFromPort(port) {
    return {
        kind: 'serial',
        label: 'Printer USB (Serial)',
        async write(data) {
            const writer = port.writable.getWriter();
            try {
                await writer.write(data);
            } finally {
                writer.releaseLock();
            }
        },
        async disconnect() {
            try {
                await port.close();
            } catch {
                /* abaikan */
            }
        }
    };
}

/**
 * @param {USBDeviceLike} device
 * @returns {Promise<PrinterConnection>}
 */
async function usbConnFromDevice(device) {
    await device.open();
    if (device.configuration === null) await device.selectConfiguration(1);
    let ifaceNum = 0;
    let epOut = 1;
    for (const iface of device.configuration.interfaces) {
        for (const alt of iface.alternates) {
            if (alt.interfaceClass === 7 || alt.interfaceClass === 0xff) {
                ifaceNum = iface.interfaceNumber;
                const out = alt.endpoints.find((e) => e.direction === 'out');
                if (out) epOut = out.endpointNumber;
            }
        }
    }
    await device.claimInterface(ifaceNum);
    return {
        kind: 'usb',
        label: device.productName || 'Printer USB',
        async write(data) {
            const CHUNK = 4096;
            for (let i = 0; i < data.length; i += CHUNK) {
                await device.transferOut(epOut, data.slice(i, i + CHUNK));
            }
        },
        async disconnect() {
            try {
                await device.close();
            } catch {
                /* abaikan */
            }
        }
    };
}

/**
 * @param {Navigator} nav
 * @returns {Promise<PrinterConnection>}
 */
export async function connectBluetooth(nav) {
    if (!nav.bluetooth) throw new Error('Browser tidak mendukung Web Bluetooth (pakai Chrome/Edge).');
    const device = await nav.bluetooth.requestDevice({
        acceptAllDevices: true,
        optionalServices: BT_SERVICES
    });
    active = await bluetoothConnFromDevice(device);
    return active;
}

/**
 * @param {Navigator} nav
 * @returns {Promise<PrinterConnection>}
 */
export async function connectSerial(nav) {
    if (!nav.serial) throw new Error('Browser tidak mendukung Web Serial (pakai Chrome/Edge desktop).');
    const port = await nav.serial.requestPort();
    await port.open({ baudRate: 9600 });
    active = serialConnFromPort(port);
    return active;
}

/**
 * @param {Navigator} nav
 * @returns {Promise<PrinterConnection>}
 */
export async function connectUsb(nav) {
    if (!nav.usb) throw new Error('Browser tidak mendukung WebUSB.');
    const device = await nav.usb.requestDevice({ filters: [{ classCode: 7 }, { classCode: 0xff }] });
    active = await usbConnFromDevice(device);
    return active;
}

/**
 * @param {PrinterKind} kind
 * @param {Navigator} nav
 * @returns {Promise<PrinterConnection>}
 */
export async function connectByKind(kind, nav) {
    if (kind === 'bluetooth') return connectBluetooth(nav);
    if (kind === 'serial') return connectSerial(nav);
    return connectUsb(nav);
}

/**
 * @param {PrinterKind | null} kind
 * @param {Navigator} nav
 * @returns {Promise<PrinterConnection | null>}
 */
export async function trySilentReconnect(kind, nav) {
    if (active) return active;
    try {
        if (kind === 'serial' && nav.serial?.getPorts) {
            const ports = await nav.serial.getPorts();
            if (ports.length) {
                try {
                    await ports[0].open({ baudRate: 9600 });
                } catch {
                    /* mungkin sudah terbuka */
                }
                active = serialConnFromPort(ports[0]);
                return active;
            }
        }
        if (kind === 'bluetooth' && nav.bluetooth?.getDevices) {
            const devices = await nav.bluetooth.getDevices();
            if (devices.length) {
                active = await bluetoothConnFromDevice(devices[0]);
                return active;
            }
        }
        if (kind === 'usb' && nav.usb?.getDevices) {
            const devices = await nav.usb.getDevices();
            if (devices.length) {
                active = await usbConnFromDevice(devices[0]);
                return active;
            }
        }
    } catch {
        /* gagal silent */
    }
    return null;
}

/** @typedef {import('./printStorage.js').StoredPrinter} StoredPrinter */

/**
 * @typedef {Object} BluetoothCharacteristicLike
 * @property {{ write?: boolean, writeWithoutResponse?: boolean }} properties
 * @property {(data: BufferSource) => Promise<void>} writeValue
 * @property {(data: BufferSource) => Promise<void>} [writeValueWithoutResponse]
 */

/**
 * @typedef {Object} BluetoothDeviceLike
 * @property {string} [name]
 * @property {{ connect(): Promise<{ getPrimaryServices(): Promise<{ getCharacteristics(): Promise<BluetoothCharacteristicLike[]> }[]> }>, disconnect(): void }} gatt
 */

/**
 * @typedef {Object} SerialPortLike
 * @property {(opts: { baudRate: number }) => Promise<void>} open
 * @property {() => Promise<void>} close
 * @property {{ getWriter(): { write(data: Uint8Array): Promise<void>, releaseLock(): void } }} writable
 */

/**
 * @typedef {Object} USBDeviceLike
 * @property {string} [productName]
 * @property {{ interfaces: { interfaceNumber: number, alternates: { interfaceClass: number, endpoints: { direction: string, endpointNumber: number }[] }[] }[] } | null} configuration
 * @property {() => Promise<void>} open
 * @property {(n: number) => Promise<void>} selectConfiguration
 * @property {(n: number) => Promise<void>} claimInterface
 * @property {(ep: number, data: Uint8Array) => Promise<void>} transferOut
 * @property {() => Promise<void>} close
 */

export { BT_SERVICES };

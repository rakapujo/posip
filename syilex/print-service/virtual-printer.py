"""
Virtual Thermal Printer - Simulates a network thermal printer on port 9100.
Receives ESC/POS data and renders readable output in terminal.

Usage: python virtual-printer.py
Then add network printer in SIPOS: 127.0.0.1:9100
"""

import socket
import re
import threading

HOST = "0.0.0.0"
PORT = 9100
CHAR_WIDTH = 48

def strip_escpos(data):
    """Strip ESC/POS control codes and render readable text."""
    text = data.decode("cp437", errors="replace")
    # Remove ESC/POS sequences (ESC + 1-3 bytes, GS + 1-3 bytes)
    clean = re.sub(r'[\x1b][\x00-\x7f]{1,5}', '', clean if 'clean' in dir() else text)
    clean = re.sub(r'[\x1d][\x00-\x7f]{1,5}', '', clean)
    # Remove remaining control chars except newline
    clean = re.sub(r'[\x00-\x09\x0b-\x1f\x7f]', '', clean)
    return clean

def render_receipt(data):
    """Parse ESC/POS bytes and render a simulated thermal receipt."""
    lines = []
    current_line = ""
    bold = False
    double = False
    align = "left"  # left, center, right

    i = 0
    raw = data
    while i < len(raw):
        b = raw[i]

        # ESC sequences (0x1B)
        if b == 0x1B:
            if i + 1 < len(raw):
                cmd = raw[i + 1]
                if cmd == 0x40:  # ESC @ = init
                    i += 2; continue
                elif cmd == 0x61:  # ESC a = align
                    if i + 2 < len(raw):
                        a = raw[i + 2]
                        align = {0: "left", 1: "center", 2: "right"}.get(a, "left")
                    i += 3; continue
                elif cmd == 0x45:  # ESC E = bold
                    if i + 2 < len(raw):
                        bold = raw[i + 2] > 0
                    i += 3; continue
                elif cmd == 0x21:  # ESC ! = print mode
                    if i + 2 < len(raw):
                        mode = raw[i + 2]
                        double = (mode & 0x30) != 0
                    i += 3; continue
                elif cmd == 0x64:  # ESC d = feed
                    if i + 2 < len(raw):
                        n = raw[i + 2]
                        for _ in range(n):
                            lines.append("")
                    i += 3; continue
                elif cmd == 0x70:  # ESC p = open drawer
                    i += 5; continue
                else:
                    i += 2; continue
            i += 1; continue

        # GS sequences (0x1D)
        if b == 0x1D:
            if i + 1 < len(raw):
                cmd = raw[i + 1]
                if cmd == 0x56:  # GS V = cut
                    lines.append("x" + "-" * (CHAR_WIDTH - 1))
                    i += 3; continue
                else:
                    i += 2; continue
            i += 1; continue

        # Newline
        if b == 0x0A:
            w = CHAR_WIDTH // 2 if double else CHAR_WIDTH
            prefix = ""
            if bold and double:
                prefix = "## "
            elif bold:
                prefix = "* "
            elif double:
                prefix = "# "

            text = current_line
            if align == "center":
                pad = max(0, (w - len(text)) // 2)
                text = " " * pad + text
            elif align == "right":
                pad = max(0, w - len(text))
                text = " " * pad + text

            lines.append(prefix + text)
            current_line = ""
            i += 1; continue

        # Regular char
        if 0x20 <= b < 0x7F or b >= 0x80:
            current_line += chr(b) if b < 0x80 else data[i:i+1].decode("cp437", errors="replace")

        i += 1

    if current_line:
        lines.append(current_line)

    return "\n".join(lines)


def handle_client(conn, addr):
    """Handle incoming printer connection."""
    print(f"\n{'=' * 52}")
    print(f"  Connection from {addr[0]}:{addr[1]}")
    print(f"{'=' * 52}")

    data = bytearray()
    while True:
        try:
            chunk = conn.recv(4096)
            if not chunk:
                break
            data.extend(chunk)
        except socket.timeout:
            break

    conn.close()

    if data:
        print(f"  Received {len(data)} bytes\n")
        print("+" + "-" * CHAR_WIDTH + "+")
        receipt = render_receipt(bytes(data))
        for line in receipt.split("\n"):
            display = line[:CHAR_WIDTH].ljust(CHAR_WIDTH)
            print(f"|{display}|")
        print("+" + "-" * CHAR_WIDTH + "+")
        print()
    else:
        print("  (empty data)\n")


def main():
    print()
    print("=" * 50)
    print("  POSIP Virtual Thermal Printer")
    print("  Listening on port 9100")
    print("=" * 50)
    print("  Add network printer in config:")
    print("  IP: 127.0.0.1  Port: 9100")
    print("  Press Ctrl+C to stop")
    print("=" * 50)
    print()

    server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    server.bind((HOST, PORT))
    server.listen(5)

    try:
        while True:
            conn, addr = server.accept()
            conn.settimeout(2)
            t = threading.Thread(target=handle_client, args=(conn, addr))
            t.daemon = True
            t.start()
    except KeyboardInterrupt:
        print("\nStopped.")
    finally:
        server.close()


if __name__ == "__main__":
    main()

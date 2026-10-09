"""Offline integration tests for the public one-command installers.

Only temporary fixtures are installed; the real Releases and gateway never run.
Run: python .github/tests/install-scripts.py
"""

import gzip
import hashlib
import http.server
import io
import json
import os
import pathlib
import platform
import shutil
import subprocess
import tarfile
import tempfile
import threading
import unittest
import zipfile


ROOT = pathlib.Path(__file__).resolve().parents[2]
REPO = "runchance/rcmaker-codex-gateway"
EXTRAS = {name: (name + " fixture\n").encode() for name in
          (".env.example", "LICENSE", "NOTICE", "THIRD_PARTY_NOTICES")}


def release_assets(tag, target):
    """Build an inert archive matching the exact publisher's asset format."""
    system, arch = target
    stem = f"rcmaker-codex-gateway-{tag}-{system}-{arch}"
    exe = "rcmaker-codex-gateway.exe" if system == "windows" else "rcmaker-codex-gateway"
    filename = stem + (".zip" if system == "windows" else ".tar.gz")
    binary = (b"MZ" if system == "windows" else b"\x7fELF") + tag.encode() * 40
    # Older releases may omit the optional template; new releases include it.
    members = {exe: binary, **EXTRAS}
    if tag == "v1.0.0":
        del members[".env.example"]
    buffer = io.BytesIO()
    if system == "windows":
        with zipfile.ZipFile(buffer, "w", zipfile.ZIP_DEFLATED) as archive:
            for name, content in members.items():
                archive.writestr(name, content)
    else:
        with gzip.GzipFile(fileobj=buffer, mode="wb", mtime=0) as compressed:
            with tarfile.open(fileobj=compressed, mode="w|") as archive:
                for name, content in members.items():
                    info = tarfile.TarInfo(name)
                    info.size = len(content)
                    info.mode = 0o755 if name == exe else 0o644
                    archive.addfile(info, io.BytesIO(content))
    checksum = (f"{hashlib.sha256(binary).hexdigest()}  {stem + ('.exe' if system == 'windows' else '')}\n"
                f"{hashlib.sha256(buffer.getvalue()).hexdigest()}  {filename}\n").encode()
    return {filename: buffer.getvalue(), "SHA256SUMS": checksum}, binary


def combined_checksums(tag):
    """The real Release publishes one SHA256SUMS covering every platform."""
    lines = []
    for target in (("windows", "x86_64"), ("linux", "x86_64"), ("linux", "aarch64")):
        assets, _ = release_assets(tag, target)
        for name, content in assets.items():
            if name != "SHA256SUMS":
                lines.append(f"{hashlib.sha256(content).hexdigest()}  {name}")
        lines.append(assets["SHA256SUMS"].decode().splitlines()[0])
    return ("\n".join(lines) + "\n").encode()


class ReleaseServer(http.server.ThreadingHTTPServer):
    """Serve a mutable fake latest Release with downloadable assets."""

    def __init__(self):
        super().__init__(("127.0.0.1", 0), ReleaseHandler)
        self.tag = "v1.0.0"
        self.tamper = False

    @property
    def base(self):
        return f"http://127.0.0.1:{self.server_port}"


class ReleaseHandler(http.server.BaseHTTPRequestHandler):
    """Mock just the GitHub endpoints required by the release installers."""

    def log_message(self, *_args):
        pass

    def do_GET(self):
        srv = self.server
        basepath = f"/{REPO}/releases"
        target = ("windows", "x86_64") if "windows" in self.path else None
        if self.path == f"{basepath}/latest":
            self.send_response(302)
            self.send_header("Location", srv.base + f"{basepath}/tag/{srv.tag}")
            self.end_headers()
            return
        if self.path == f"{basepath}/tag/{srv.tag}":
            return self.reply(b"release page")
        if self.path == f"/repos/{REPO}/releases/latest":
            assets, _ = release_assets(srv.tag, ("windows", "x86_64"))
            payload = json.dumps({
                "tag_name": srv.tag, "draft": False, "prerelease": False,
                "assets": [
                    {"name": name, "state": "uploaded", "browser_download_url":
                     f"{srv.base}{basepath}/download/{srv.tag}/{name}"}
                    for name in assets
                ],
            }).encode()
            return self.reply(payload, "application/json")
        prefix = f"{basepath}/download/{srv.tag}/"
        if self.path.startswith(prefix):
            name = self.path[len(prefix):]
            if name == "SHA256SUMS":
                return self.reply(combined_checksums(srv.tag))
            if name.endswith(".tar.gz"):
                arch = "aarch64" if "aarch64" in name else "x86_64"
                target = ("linux", arch)
            else:
                target = ("windows", "x86_64")
            assets, _ = release_assets(srv.tag, target)
            if name in assets:
                data = assets[name]
                if srv.tamper and name != "SHA256SUMS":
                    data = data + b"tampered"
                return self.reply(data)
        self.reply(b"Not Found", status=404)

    def reply(self, body, content_type="application/octet-stream", status=200):
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


class InstallerTests(unittest.TestCase):
    """Exercise clean install, no-op, upgrade and rejected corrupt download."""

    @classmethod
    def setUpClass(cls):
        cls.server = ReleaseServer()
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.thread.join()

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="rcmaker-install-fixture-")
        self.addCleanup(self.temp.cleanup)
        self.home = pathlib.Path(self.temp.name)
        self.install_dir = self.home / "install folder"
        self.script = self.home / "installer"
        self.server.tag = "v1.0.0"
        self.server.tamper = False

    def invoke(self, script, system, powershell="pwsh", pipe_mode=False):
        env = os.environ.copy()
        env["RCMAKER_INSTALL_DIR"] = str(self.install_dir)
        if system == "windows":
            if pipe_mode:
                quoted = str(script).replace("'", "''")
                command = [powershell, "-NoProfile", "-NonInteractive", "-Command",
                           f"Get-Content -Raw -LiteralPath '{quoted}' | Invoke-Expression"]
            else:
                command = [powershell, "-NoProfile", "-NonInteractive", "-File", str(script)]
        else:
            command = ["bash", str(script)]
        return subprocess.run(command, env=env, text=True, encoding="utf-8", errors="replace",
                              capture_output=True, timeout=60)

    def scenario(self, system, arch, powershell="pwsh", pipe_mode=False):
        exe = "rcmaker-codex-gateway.exe" if system == "windows" else "rcmaker-codex-gateway"
        source = (ROOT / ".github/scripts" / ("install.ps1" if system == "windows" else "install.sh")).read_text()
        if system == "windows":
            source = source.replace("https://api.github.com/repos/$repository/releases/latest",
                                    f"{self.server.base}/repos/$repository/releases/latest")
            source = source.replace("https://github.com/$repository/releases/download/$tag/$name",
                                    f"{self.server.base}/{REPO}/releases/download/$tag/$name")
            self.script = self.script.with_suffix(".ps1")
        else:
            source = source.replace("https://github.com/runchance/rcmaker-codex-gateway",
                                    f"{self.server.base}/{REPO}")
            source = source.replace("--proto '=https' --proto-redir '=https'",
                                    "--proto '=http' --proto-redir '=http'")
            self.script = self.script.with_suffix(".sh")
        self.script.write_bytes(source.encode("utf-8"))
        target = self.install_dir / exe

        def check_success(expected_tag):
            completed = self.invoke(self.script, system, powershell, pipe_mode)
            self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
            self.assertTrue(target.is_file())
            self.assertEqual(target.read_bytes(), release_assets(expected_tag, (system, arch))[1])
            return completed

        check_success("v1.0.0")
        (self.install_dir / "data").mkdir()
        (self.install_dir / "data" / "keep.db").write_text("private state")
        (self.install_dir / ".env").write_text("secret unchanged")
        (self.install_dir / "runtime").mkdir()
        (self.install_dir / "runtime" / "keep.txt").write_text("runtime")
        check_success("v1.0.0")

        self.server.tag = "v1.0.1"
        self.server.tamper = True
        failed = self.invoke(self.script, system, powershell, pipe_mode)
        self.assertNotEqual(failed.returncode, 0, failed.stdout + failed.stderr)
        self.assertEqual(target.read_bytes(), release_assets("v1.0.0", (system, arch))[1])
        self.server.tamper = False

        check_success("v1.0.1")
        self.assertEqual((self.install_dir / ".env.example").read_bytes(), EXTRAS[".env.example"])
        self.assertEqual((self.install_dir / f"{exe}.previous").read_bytes(),
                         release_assets("v1.0.0", (system, arch))[1])
        self.assertEqual((self.install_dir / "data" / "keep.db").read_text(), "private state")
        self.assertEqual((self.install_dir / ".env").read_text(), "secret unchanged")
        self.assertEqual((self.install_dir / "runtime" / "keep.txt").read_text(), "runtime")
        self.assertFalse(list(self.install_dir.glob(".install-*")), "staging was not removed")

    @unittest.skipUnless(os.name == "nt" and shutil.which("pwsh"), "Windows PowerShell 7 required")
    def test_windows_installer(self):
        self.scenario("windows", "x86_64")

    @unittest.skipUnless(os.name == "nt" and shutil.which("powershell"),
                         "Windows PowerShell 5 required")
    def test_windows_powershell_pipe(self):
        self.scenario("windows", "x86_64", powershell="powershell", pipe_mode=True)

    @unittest.skipUnless(os.name != "nt" and shutil.which("bash"), "Linux Bash required")
    def test_linux_installer(self):
        arch = "aarch64" if platform.machine() in ("aarch64", "arm64") else "x86_64"
        self.scenario("linux", arch)


if __name__ == "__main__":
    unittest.main(verbosity=2)

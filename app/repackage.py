#!/usr/bin/env python3
"""Inject the home-screen app icon into the freshly built IPA, repackage it,
and bump the AltStore source. Usage: repackage.py <version> <description>"""
import os, sys, shutil, plistlib, zipfile, json, datetime

version = sys.argv[1] if len(sys.argv) > 1 else "1.0"
desc = sys.argv[2] if len(sys.argv) > 2 else "Update."

WORK = "/tmp/ipawork"
SRC = "/var/www/html8/SingOpKoelsch-iOS/build/ipa/SingOpKoelsch.ipa"
OUT = "/var/www/html8/app/SingOpKoelsch.ipa"
ICONS = {
    "/tmp/ic_120.png": "AppIcon60x60@2x.png",
    "/tmp/ic_180.png": "AppIcon60x60@3x.png",
    "/tmp/ic_152.png": "AppIcon76x76@2x.png",
    "/tmp/ic_167.png": "AppIcon83.5x83.5@2x.png",
}

from PIL import Image

shutil.rmtree(WORK, ignore_errors=True)
os.makedirs(WORK)
with zipfile.ZipFile(SRC) as z:
    z.extractall(WORK)
app = os.path.join(WORK, "Payload", "SingOpKoelsch.app")
assert os.path.isdir(app), "app bundle missing"

for src, name in ICONS.items():
    Image.open(src).convert("RGB").save(os.path.join(app, name))

ip = os.path.join(app, "Info.plist")
d = plistlib.load(open(ip, "rb"))
d["CFBundleIconName"] = "AppIcon"
d["CFBundleIcons"] = {"CFBundlePrimaryIcon": {"CFBundleIconFiles": ["AppIcon60x60"], "CFBundleIconName": "AppIcon"}}
d["CFBundleIcons~ipad"] = {"CFBundlePrimaryIcon": {"CFBundleIconFiles": ["AppIcon60x60", "AppIcon76x76", "AppIcon83.5x83.5"], "CFBundleIconName": "AppIcon"}}
plistlib.dump(d, open(ip, "wb"), fmt=plistlib.FMT_BINARY)

if os.path.exists(OUT):
    os.remove(OUT)
with zipfile.ZipFile(OUT, "w", zipfile.ZIP_DEFLATED) as z:
    for root, _, files in os.walk(os.path.join(WORK, "Payload")):
        for f in files:
            full = os.path.join(root, f)
            z.write(full, os.path.relpath(full, WORK))
size = os.path.getsize(OUT)

p = "/var/www/html8/app/altstore.json"
src = json.load(open(p))
a = src["apps"][0]
today = datetime.date.today().isoformat()
a.update(version=version, versionDate=today, versionDescription=desc, size=size)
a["versions"] = [{"version": version, "date": today, "localizedDescription": desc,
                  "downloadURL": a["downloadURL"], "size": size, "minOSVersion": "17.0"}]
json.dump(src, open(p, "w"), ensure_ascii=False, indent=2)

shutil.copyfile(OUT, SRC)  # keep build/ipa copy in sync
with open("/tmp/repackage-result.txt", "w") as f:
    f.write("ipa_version=%s\nsize=%s\nappbound=%s\nicons=%s\n" % (
        d.get("CFBundleShortVersionString"), size, d.get("WKAppBoundDomains"), bool(d.get("CFBundleIcons"))))
print("done")

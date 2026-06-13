#!/usr/bin/env python3
"""
inject_icons.py — Resize the 1024px app icon and inject all required sizes
into the built IPA, then update Info.plist so iOS can find them.

Usage:  python3 inject_icons.py <path/to/SingOpKoelsch.ipa>
Output: overwrites the IPA in-place.
"""
import sys, os, shutil, zipfile, plistlib, tempfile
from PIL import Image

IPA_PATH   = sys.argv[1] if len(sys.argv) > 1 else "build/ipa/SingOpKoelsch.ipa"
ICON_SRC   = os.path.join(os.path.dirname(__file__),
             "SingOpKoelsch/Resources/Assets.xcassets/AppIcon.appiconset/icon-1024.png")

# All sizes iOS / iPadOS need (name: side_points, scale)
ICONS = [
    ("AppIcon20x20@1x.png",    20,  1),
    ("AppIcon20x20@2x.png",    20,  2),
    ("AppIcon20x20@3x.png",    20,  3),
    ("AppIcon29x29@1x.png",    29,  1),
    ("AppIcon29x29@2x.png",    29,  2),
    ("AppIcon29x29@3x.png",    29,  3),
    ("AppIcon40x40@1x.png",    40,  1),
    ("AppIcon40x40@2x.png",    40,  2),
    ("AppIcon40x40@3x.png",    40,  3),
    ("AppIcon60x60@2x.png",    60,  2),   # iPhone home screen
    ("AppIcon60x60@3x.png",    60,  3),   # iPhone Pro home screen
    ("AppIcon76x76@1x.png",    76,  1),
    ("AppIcon76x76@2x.png",    76,  2),   # iPad home screen
    ("AppIcon83.5x83.5@2x.png", 83, 2),  # iPad Pro home screen (83.5*2=167)
    ("AppIcon1024x1024@1x.png", 1024, 1), # App Store / system
]

def px(pts, scale): return int(pts * scale)

src = Image.open(ICON_SRC).convert("RGBA")

tmpdir = tempfile.mkdtemp()
try:
    # 1. Unzip IPA
    extract_dir = os.path.join(tmpdir, "ipa")
    with zipfile.ZipFile(IPA_PATH, "r") as z:
        z.extractall(extract_dir)

    # 2. Find .app bundle
    payload = os.path.join(extract_dir, "Payload")
    apps = [d for d in os.listdir(payload) if d.endswith(".app")]
    if not apps:
        print("ERROR: No .app found in Payload/"); sys.exit(1)
    app_dir = os.path.join(payload, apps[0])

    # 3. Write resized icons into bundle root
    icon_files = []
    for name, pts, scale in ICONS:
        size = px(pts, scale)
        resized = src.resize((size, size), Image.LANCZOS)
        out_path = os.path.join(app_dir, name)
        resized.save(out_path, "PNG", optimize=True)
        icon_files.append(name)
        print(f"  {name} ({size}x{size})")

    # 4. Update Info.plist with CFBundleIcons
    plist_path = os.path.join(app_dir, "Info.plist")
    with open(plist_path, "rb") as f:
        plist = plistlib.load(f)

    primary_files = [n for n in [
        "AppIcon60x60@2x", "AppIcon60x60@3x",
        "AppIcon76x76@2x", "AppIcon83.5x83.5@2x",
        "AppIcon40x40@2x", "AppIcon40x40@3x",
        "AppIcon29x29@2x", "AppIcon29x29@3x",
    ]]

    plist["CFBundleIcons"] = {
        "CFBundlePrimaryIcon": {
            "CFBundleIconFiles": primary_files,
            "CFBundleIconName":  "AppIcon",
        }
    }
    plist["CFBundleIcons~ipad"] = {
        "CFBundlePrimaryIcon": {
            "CFBundleIconFiles": primary_files,
            "CFBundleIconName":  "AppIcon",
        }
    }

    with open(plist_path, "wb") as f:
        plistlib.dump(plist, f)

    # 5. Repack IPA
    tmp_ipa = IPA_PATH + ".tmp"
    with zipfile.ZipFile(tmp_ipa, "w", zipfile.ZIP_DEFLATED) as z:
        for root, dirs, files in os.walk(extract_dir):
            for file in files:
                abs_path = os.path.join(root, file)
                arc_path = os.path.relpath(abs_path, extract_dir)
                z.write(abs_path, arc_path)
    os.replace(tmp_ipa, IPA_PATH)
    print(f"\nIcon injection complete → {IPA_PATH}")

finally:
    shutil.rmtree(tmpdir, ignore_errors=True)

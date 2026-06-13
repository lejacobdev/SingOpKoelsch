import asyncio
import os
from aiohttp import web
from shazamio import Shazam
import tempfile
import json

# Debugging aktiv
DEBUG = True

async def recognize(request):
    reader = await request.multipart()
    
    field = await reader.next()
    if field.name != 'audio':
        return web.json_response({'error': True, 'message': 'Keine Audio-Datei gefunden'})
    
    # Temporäre Datei speichern
    temp_dir = tempfile.gettempdir()
    temp_file = os.path.join(temp_dir, 'recording.webm')
    
    size = 0
    with open(temp_file, 'wb') as f:
        while True:
            chunk = await field.read_chunk()  # 8192 bytes default
            if not chunk:
                break
            size += len(chunk)
            f.write(chunk)
    
    if DEBUG:
        print(f"[DEBUG] Audio empfangen: {temp_file} ({size} bytes)")
    
    # Song erkennen
    shazam = Shazam()
    try:
        out = await shazam.recognize(temp_file)
        if DEBUG:
            print(f"[DEBUG] Shazam-Ergebnis: {out}")
    except Exception as e:
        print(f"[ERROR] Shazam-Erkennung fehlgeschlagen: {e}")
        return web.json_response({'error': True, 'message': str(e)})

    if 'track' in out and out['track']:
        track = out['track']
        result = {
            'error': False,
            'title': track.get('title', ''),
            'subtitle': track.get('subtitle', ''),
            'images': track.get('images', {}),
            'url': track.get('url', '')
        }
        return web.json_response(result)
    else:
        return web.json_response({'error': True, 'message': 'Kein Song erkannt'})

# Webserver starten
app = web.Application()
app.add_routes([web.post('/recognize', recognize)])

if __name__ == '__main__':
    web.run_app(app, host='127.0.0.1', port=5010)

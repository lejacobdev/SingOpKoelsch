import sys
import asyncio
import json
from shazamio import Shazam

async def recognize(file_path):
    try:
        shazam = Shazam()
        out = await shazam.recognize_song(file_path)

        track = out.get("track") or {}

        if track:
            print(json.dumps({
                "title": track.get("title"),
                "subtitle": track.get("subtitle"),
                "url": track.get("url"),
                "images": track.get("images", {})
            }))
        else:
            print(json.dumps({"error": "No match"}))

    except Exception as e:
        print(json.dumps({"error": str(e)}))

if __name__ == "__main__":
    asyncio.run(recognize(sys.argv[1]))
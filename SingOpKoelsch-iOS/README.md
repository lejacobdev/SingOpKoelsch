# Sing op Kölsch – iOS App

Native SwiftUI app for iPhone and iPad. Talks to the PHP REST API at `singopkoelsch.de/api`.

---

## Requirements

- Mac with **Xcode 15+**
- **Apple Developer account** (free works for device testing; paid required for TestFlight / App Store)
- **[XcodeGen](https://github.com/yonaskolb/XcodeGen)** — generates the `.xcodeproj` from `project.yml`

---

## 1 — One-time setup on your Mac

```bash
# Install XcodeGen
brew install xcodegen

# Clone or copy this folder to your Mac, then:
cd SingOpKoelsch-iOS
xcodegen generate          # creates SingOpKoelsch.xcodeproj
open SingOpKoelsch.xcodeproj
```

In Xcode:
1. Select the **SingOpKoelsch** target → **Signing & Capabilities**
2. Set your **Team** (Apple ID)
3. Connect your iPhone/iPad → select it as the run destination → **⌘R**

---

## 2 — Push notifications (APNs)

Push notifications replace the email notification system.  
You need to configure APNs once on the server.

### a) Create an APNs Auth Key in Apple Developer Portal

1. Go to [developer.apple.com](https://developer.apple.com) → Certificates, IDs & Profiles → Keys
2. Create a new key, enable **Apple Push Notifications service (APNs)**
3. Download the `.p8` file — you can only download it once
4. Note your **Key ID** (10 chars) and **Team ID** (10 chars, shown in top-right of the portal)

### b) Upload the key to your server

```bash
scp AuthKey_XXXXXXXXXX.p8 your-server:/var/www/html8/apns/
```

### c) Add to `config.php`

```php
define('APNS_KEY_PATH',  '/var/www/html8/apns/AuthKey_XXXXXXXXXX.p8');
define('APNS_KEY_ID',    'XXXXXXXXXX');   // your Key ID
define('APNS_TEAM_ID',   'YYYYYYYYYY');   // your Team ID
define('APNS_BUNDLE_ID', 'de.singopkoelsch.app');
```

### d) Switch to production in the app

When building for App Store / TestFlight, change this line in `NotificationManager.swift`:

```swift
// sandbox = false for production builds
try await api.registerDeviceToken(token, sandbox: false)
```

And in `SingOpKoelsch.entitlements`, change `development` → `production`:

```xml
<string>production</string>
```

---

## 3 — App Store / TestFlight

1. In Xcode, bump `CFBundleVersion` in `project.yml`
2. Run `xcodegen generate` again
3. **Product → Archive** → upload to App Store Connect
4. Distribute via TestFlight for beta testing

---

## API base URL

Set in `APIClient.swift`:

```swift
let API_BASE = "https://singopkoelsch.de/api"
```

---

## Features

| Feature | Status |
|---------|--------|
| Login / Register / Forgot password | ✅ |
| Browse & search songs | ✅ |
| Song detail + lyrics | ✅ |
| Propose lyric changes | ✅ |
| My proposals (with status) | ✅ |
| Browse bands + band detail | ✅ |
| Profile: name, password, prefs | ✅ |
| Notification settings (per-day max) | ✅ |
| Admin: stats dashboard | ✅ |
| Admin: approve / reject proposals | ✅ |
| Push notifications (APNs) | ✅ |
| iPhone + iPad adaptive layout | ✅ |
| Dark mode | ✅ (system) |

---

## Project structure

```
SingOpKoelsch/
├── App/
│   ├── SingOpKoelschApp.swift   Entry point
│   └── AppDelegate.swift        APNs token handling
├── Models/
│   ├── Song.swift
│   └── Models.swift             User, Band, Proposal, Stats
├── Services/
│   ├── APIClient.swift          All REST calls
│   ├── AuthManager.swift        Login state + keychain token
│   ├── NotificationManager.swift APNs registration
│   └── KeychainService.swift
├── Views/
│   ├── RootView.swift           Auth gate
│   ├── MainTabView.swift        Tab bar + notification routing
│   ├── Auth/                    Login, Register, ForgotPassword
│   ├── Songs/                   List, Detail, ProposeChange, MyProposals
│   ├── Bands/                   Grid, BandDetail
│   ├── Profile/                 Settings (name, pw, notif, lang)
│   ├── Admin/                   Dashboard, ProposalReview
│   └── Shared/                  Theme, components
└── Resources/
    └── Assets.xcassets
```

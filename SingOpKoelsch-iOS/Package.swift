// swift-tools-version: 5.9

import PackageDescription

let package = Package(
    name: "SingOpKoelsch",
    platforms: [
        .iOS(.v17),
    ],
    products: [
        .library(
            name: "SingOpKoelsch",
            targets: ["SingOpKoelsch"]
        ),
    ],
    targets: [
        .target(
            name: "SingOpKoelsch",
            path: "SingOpKoelsch",
            exclude: [
                "SingOpKoelsch.entitlements",
                "Info.plist",
            ],
            resources: [
                .process("Resources/Assets.xcassets"),
            ],
            swiftSettings: [
                .unsafeFlags(["-swift-version", "5"]),
            ]
        ),
    ]
)

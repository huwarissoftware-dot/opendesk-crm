#!/usr/bin/env bash
# Build the OpenDesk CRM Android client APK without Gradle/Android Studio.
# Needs: a JDK (javac, keytool) and an Android SDK with build-tools 35.0.0
# and platforms/android-35. Defaults point at this machine's toolchain:
#   JAVA_HOME=~/workspace/tools/jdk17 ANDROID_HOME=~/workspace/tools/android-sdk
# Output: ../OpenDesk-CRM.apk (RELEASE-signed)
set -e
cd "$(dirname "$0")"

JAVA_HOME="${JAVA_HOME:-$HOME/workspace/tools/jdk17}"
ANDROID_HOME="${ANDROID_HOME:-$HOME/workspace/tools/android-sdk}"
JAVA_BIN="$JAVA_HOME/bin"
export PATH="$JAVA_BIN:$PATH"
BT="$ANDROID_HOME/build-tools/35.0.0"
ANDROID_JAR="$ANDROID_HOME/platforms/android-35/android.jar"
WORK=build
rm -rf "$WORK"
mkdir -p "$WORK/assets" "$WORK/classes" "$WORK/dex"

# 1. Local pages + icon shipped inside the APK
cp assets/setup.html assets/error.html assets/icon.png "$WORK/assets/"

# 2. Compile the wrapper
"$JAVA_BIN/javac" -encoding UTF-8 -source 8 -target 8 -cp "$ANDROID_JAR" -d "$WORK/classes" MainActivity.java

# 3. DEX the bytecode
"$BT/d8" --lib "$ANDROID_JAR" --output "$WORK/dex" $(find "$WORK/classes" -name '*.class')

# 4. Package
"$BT/aapt" package -f -M AndroidManifest.xml -S res --auto-add-overlay -A "$WORK/assets" -I "$ANDROID_JAR" -F "$WORK/unsigned.apk"
cd "$WORK"
( cd dex && zip -q ../unsigned.apk classes.dex )
"$BT/zipalign" -f 4 unsigned.apk aligned.apk

# 5. Sign the APK with the RELEASE keystore in release/
# (passwords live only in its 0600 keystore.properties - never printed).
# No debug fallback: release or fail.
REL_DIR="$(cd .. && pwd)/release"
REL_PROPS="$REL_DIR/keystore.properties"
if [ ! -f "$REL_PROPS" ]; then
  echo "ERROR: release keystore properties not found at $REL_PROPS - refusing to build a debug-signed APK." >&2
  exit 1
fi
KS_FILE=$(grep '^storeFile=' "$REL_PROPS" | cut -d= -f2-)
KS_PASS=$(grep '^storePassword=' "$REL_PROPS" | cut -d= -f2-)
KEY_ALIAS=$(grep '^keyAlias=' "$REL_PROPS" | cut -d= -f2-)
KEY_PASS=$(grep '^keyPassword=' "$REL_PROPS" | cut -d= -f2-)
"$BT/apksigner" sign --ks "$REL_DIR/$KS_FILE" --ks-pass "pass:$KS_PASS" \
  --key-pass "pass:$KEY_PASS" --ks-key-alias "$KEY_ALIAS" \
  --v2-signing-enabled true --out ../OpenDesk-CRM.apk aligned.apk
echo "Signed with RELEASE keystore (alias: $KEY_ALIAS)"
cd ..
"$BT/apksigner" verify --print-certs OpenDesk-CRM.apk | head -3
echo "APK built: android/OpenDesk-CRM.apk"

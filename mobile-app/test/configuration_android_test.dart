import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// P-06 — la permission INTERNET doit figurer dans le manifeste principal,
/// fusionné dans l'APK release (les manifestes debug et profile ne le sont pas).
void main() {
  test('le manifeste principal déclare la permission INTERNET', () {
    final manifeste = File('android/app/src/main/AndroidManifest.xml').readAsStringSync();
    expect(manifeste, contains('android:name="android.permission.INTERNET"'));
  });
}

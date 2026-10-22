import java.util.Properties

plugins {
    id("com.android.application")
    id("kotlin-android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// ── Signature de distribution ─────────────────────────────────────────────────
// Jamais de clé ni de mot de passe dans le dépôt. Sources, par priorité :
//   1. variables d'environnement (workflow mobile-release.yml, secrets GitHub) :
//      SENAGRILINK_KEYSTORE_PATH, SENAGRILINK_KEYSTORE_PASSWORD,
//      SENAGRILINK_KEY_ALIAS, SENAGRILINK_KEY_PASSWORD ;
//   2. android/key.properties sur un poste local (ignoré par Git) :
//      storeFile, storePassword, keyAlias, keyPassword.
// Sans configuration, la build release reste signée avec la clé de debug : elle
// ne sert qu'aux tests (CI, installation manuelle) et ne doit jamais être
// distribuée. SENAGRILINK_EXIGER_SIGNATURE=true fait échouer la build au lieu
// de retomber sur la clé de debug (utilisé par le workflow de publication).
val proprietesSignature = Properties().apply {
    val fichier = rootProject.file("key.properties")
    if (fichier.exists()) fichier.inputStream().use { load(it) }
}

fun valeurSignature(variable: String, propriete: String): String? =
    System.getenv(variable)?.takeIf { it.isNotBlank() } ?: proprietesSignature.getProperty(propriete)?.takeIf { it.isNotBlank() }

val signatureDistribution = mapOf(
    "storeFile" to valeurSignature("SENAGRILINK_KEYSTORE_PATH", "storeFile"),
    "storePassword" to valeurSignature("SENAGRILINK_KEYSTORE_PASSWORD", "storePassword"),
    "keyAlias" to valeurSignature("SENAGRILINK_KEY_ALIAS", "keyAlias"),
    "keyPassword" to valeurSignature("SENAGRILINK_KEY_PASSWORD", "keyPassword"),
)
val signatureDistributionComplete = signatureDistribution.values.all { it != null }

if (System.getenv("SENAGRILINK_EXIGER_SIGNATURE") == "true" && !signatureDistributionComplete) {
    val manquantes = signatureDistribution.filterValues { it == null }.keys.joinToString()
    throw GradleException(
        "Signature de distribution exigée mais incomplète (manquant : $manquantes). " +
            "Aucune build de distribution n'est produite avec la clé de debug."
    )
}

android {
    namespace = "com.agriplatform.agri_platform_mobile"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = JavaVersion.VERSION_17.toString()
    }

    defaultConfig {
        // TODO: Specify your own unique Application ID (https://developer.android.com/studio/build/application-id.html).
        applicationId = "com.agriplatform.agri_platform_mobile"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        if (signatureDistributionComplete) {
            create("distribution") {
                storeFile = file(signatureDistribution.getValue("storeFile")!!)
                storePassword = signatureDistribution.getValue("storePassword")
                keyAlias = signatureDistribution.getValue("keyAlias")
                keyPassword = signatureDistribution.getValue("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            // Clé de distribution si elle est configurée (voir en tête de fichier),
            // sinon clé de debug : build de TEST uniquement, jamais distribuée.
            signingConfig = signingConfigs.getByName(if (signatureDistributionComplete) "distribution" else "debug")
            // Réduction et obscurcissement du code (R8), comme Flutter le fait par défaut
            // en release ; écrit explicitement (Sonar kotlin:S7204).
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
    }
}

flutter {
    source = "../.."
}

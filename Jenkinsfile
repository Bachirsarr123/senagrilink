pipeline {
    agent any

    environment {
        SONAR_HOST_URL   = 'http://sonarqube:9000'
        COMPOSER_HOME    = "${WORKSPACE}/.composer"
        NODE_OPTIONS     = '--max-old-space-size=2048'
    }

    options {
        timeout(time: 30, unit: 'MINUTES')
        disableConcurrentBuilds()
    }

    stages {

        // ── 1. Checkout ───────────────────────────────────────────────────────
        stage('Checkout') {
            steps {
                checkout scm
                echo "Branche : ${env.BRANCH_NAME} — commit : ${env.GIT_COMMIT?.take(8)}"
            }
        }

        // ── 2. Dépendances Backend ────────────────────────────────────────────
        stage('Dépendances Backend') {
            steps {
                dir('backend') {
                    sh 'composer install --no-interaction --prefer-dist --optimize-autoloader --no-progress'
                    echo 'Dépendances Laravel installées.'
                }
            }
        }

        // ── 3. Dépendances Frontend ───────────────────────────────────────────
        stage('Dépendances Frontend') {
            steps {
                dir('frontend-web') {
                    sh 'npm ci --prefer-offline'
                    echo 'Dépendances Angular installées.'
                }
            }
        }

        // ── 4. Tests Backend (PHPUnit) ────────────────────────────────────────
        stage('Tests Backend') {
            steps {
                dir('backend') {
                    // Préparer l'environnement de test
                    sh 'cp .env.testing .env'
                    sh 'php artisan key:generate --force'

                    // Exécuter les tests avec rapport JUnit et couverture Clover
                    // XDEBUG_MODE=coverage requiert l'extension Xdebug sur l'agent
                    sh '''
                        XDEBUG_MODE=coverage php artisan test \
                            --log-junit=test-report.xml \
                            --coverage-clover=coverage.xml \
                            2>&1 | tee test-output.log
                    '''
                }
            }
            post {
                always {
                    // Publier les résultats JUnit dans Jenkins
                    junit 'backend/test-report.xml'
                }
            }
        }

        // ── 5. Build Angular (vérification production) ────────────────────────
        stage('Build Frontend') {
            steps {
                dir('frontend-web') {
                    sh 'npx ng build --configuration=production'
                    echo 'Build Angular production réussi.'
                }
            }
        }

        // ── 6. Analyse SonarQube ──────────────────────────────────────────────
        stage('Analyse SonarQube') {
            steps {
                withSonarQubeEnv('SonarQube') {
                    sh '''
                        sonar-scanner \
                            -Dsonar.branch.name=${BRANCH_NAME}
                    '''
                }
                echo 'Analyse SonarQube soumise.'
            }
        }

        // ── 7. Quality Gate ───────────────────────────────────────────────────
        stage('Quality Gate') {
            steps {
                // Attendre le résultat de l'analyse SonarQube (max 5 min)
                timeout(time: 5, unit: 'MINUTES') {
                    waitForQualityGate abortPipeline: true
                }
            }
        }

        // ── 8. Build Docker ───────────────────────────────────────────────────
        stage('Build Docker') {
            when {
                // Ne builder les images que sur la branche principale
                branch 'main'
            }
            steps {
                sh 'docker compose build --no-cache'
                echo 'Images Docker construites.'
            }
        }
    }

    post {
        success {
            echo "Pipeline réussi sur ${env.BRANCH_NAME}."
        }
        failure {
            echo "Pipeline échoué — consulter les logs ci-dessus."
        }
        always {
            // Nettoyer le .env de travail généré pour les tests
            dir('backend') {
                sh 'rm -f .env test-output.log || true'
            }
            cleanWs()
        }
    }
}

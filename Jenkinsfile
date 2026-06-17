pipeline {
    agent any

    environment {
        SONAR_HOST_URL = 'http://sonarqube:9000'
    }

    stages {
        stage('Checkout') {
            steps {
                checkout scm
                echo 'Code récupéré depuis GitHub.'
            }
        }

        stage('Installation des dépendances Backend') {
            steps {
                dir('backend') {
                    sh 'composer install --no-interaction --prefer-dist --optimize-autoloader'
                }
                echo 'Dépendances Laravel installées.'
            }
        }

        stage('Installation des dépendances Frontend') {
            steps {
                dir('frontend-web') {
                    sh 'npm install'
                }
                echo 'Dépendances Angular installées.'
            }
        }

        stage('Tests Backend (PHPUnit)') {
            steps {
                dir('backend') {
                    sh 'cp .env.testing .env'
                    sh 'php artisan key:generate'
                    sh 'php artisan test --testsuite=Unit --coverage-clover coverage.xml'
                }
                echo 'Tests PHPUnit terminés.'
            }
        }

        stage('Analyse SonarQube') {
            steps {
                withSonarQubeEnv('SonarQube') {
                    sh 'sonar-scanner'
                }
                echo 'Analyse SonarQube lancée.'
            }
        }

        stage('Build Docker') {
            steps {
                sh 'docker-compose build --no-cache'
                echo 'Images Docker construites.'
            }
        }
    }

    post {
        success {
            echo 'Pipeline exécuté avec succès.'
        }
        failure {
            echo 'Échec du pipeline. Vérifier les logs ci-dessus.'
        }
    }
}

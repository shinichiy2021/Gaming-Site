#!/bin/bash
set -e

echo "🎮 Gaming Hub WordPress Setup"
echo "=============================="

if ! command -v docker &> /dev/null; then
    echo "❌ Docker is not installed. Please install Docker Desktop first."
    exit 1
fi

echo "📦 Starting Docker containers..."
docker compose up -d

echo "⏳ Waiting for WordPress to be ready..."
for i in {1..30}; do
    if curl -s -o /dev/null -w "%{http_code}" http://localhost:8080 | grep -q "200\|302"; then
        echo "✅ WordPress is ready!"
        break
    fi
    if [ "$i" -eq 30 ]; then
        echo "⚠️  WordPress is taking longer than expected. Check with: docker compose logs wordpress"
        exit 1
    fi
    sleep 3
done

echo ""
echo "🎉 Setup complete!"
echo ""
echo "  WordPress:  http://localhost:8080"
echo "  phpMyAdmin: http://localhost:8081"
echo ""
echo "Next steps:"
echo "  1. Open http://localhost:8080 and complete WordPress installation"
echo "  2. Go to Appearance → Themes and activate 'Gaming Hub'"
echo "  3. Go to Settings → Reading and set 'A static page' as homepage"
echo ""
echo "Useful commands:"
echo "  docker compose up -d      # Start containers"
echo "  docker compose down       # Stop containers"
echo "  docker compose logs -f    # View logs"

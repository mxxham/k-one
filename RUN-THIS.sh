#!/bin/bash
# K-one WMS - Complete Setup & Execution Script
# Run this script to get everything working TODAY

set -e

echo "=============================================="
echo "  K-one WMS - Complete Setup & Test"
echo "=============================================="
echo ""

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Step 1: Verify prerequisites
echo -e "${BLUE}Step 1: Verifying prerequisites...${NC}"
if ! command -v node &> /dev/null; then
    echo "❌ Node.js not found. Please install Node.js first."
    exit 1
fi
if ! command -v npm &> /dev/null; then
    echo "❌ npm not found. Please install npm first."
    exit 1
fi
echo -e "${GREEN}✓ Node and npm found${NC}"
echo ""

# Step 2: Install dependencies
echo -e "${BLUE}Step 2: Installing dependencies...${NC}"
cd "$(dirname "$0")/frontend"
npm install --legacy-peer-deps
echo -e "${GREEN}✓ Dependencies installed${NC}"
echo ""

# Step 3: Start Vite dev server
echo -e "${BLUE}Step 3: Starting Vite dev server...${NC}"
echo "🚀 Vite will start on http://localhost:5173"
echo ""
echo "⚠️  IMPORTANT:"
echo "   1. Wait for the message: 'Local: http://localhost:5173/'"
echo "   2. Open that URL in your browser"
echo "   3. Login with admin / admin123"
echo "   4. Follow the testing checklist in START-HERE-TODAY.md"
echo ""
echo "Starting Vite..."
echo ""

npm run dev


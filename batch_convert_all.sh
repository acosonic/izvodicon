#!/bin/bash
#
# Batch HTML to iBank XML Converter
# Converts all Erste Bank HTML statements to XML format
#

echo "=========================================="
echo "HTML → iBank XML Batch Converter"
echo "=========================================="
echo ""

# Check dependencies
if ! python3 -c "import bs4" 2>/dev/null; then
    echo "✗ Error: BeautifulSoup4 not installed"
    echo "Install with: pip install beautifulsoup4"
    exit 1
fi

if [ ! -f "convert_html_to_xml.py" ]; then
    echo "✗ Error: convert_html_to_xml.py not found"
    exit 1
fi

count=0
failed=0

# Process all HTML statement files
for file in "Dinarski izvod"*.html "Devizni izvod"*.html; do
    if [ -f "$file" ]; then
        echo "Converting: $file"
        if python3 convert_html_to_xml.py "$file"; then
            count=$((count + 1))
            echo ""
        else
            echo "✗ Failed!"
            failed=$((failed + 1))
            echo ""
        fi
    fi
done

if [ $count -eq 0 ] && [ $failed -eq 0 ]; then
    echo "No HTML statement files found."
    echo "Looking for: 'Dinarski izvod*.html' or 'Devizni izvod*.html'"
else
    echo "=========================================="
    echo "✓ Conversion complete"
    echo "  Converted: $count"
    if [ $failed -gt 0 ]; then
        echo "  Failed: $failed"
    fi
    echo "=========================================="
fi

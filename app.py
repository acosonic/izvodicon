#!/usr/bin/env python3
"""
Flask web application for HTML to XML conversion.
Serves the web interface and handles file conversion.
"""

from flask import Flask, request, send_file, jsonify, render_template_string
from werkzeug.utils import secure_filename
import os
import tempfile
import sys
from pathlib import Path
from xml.etree.ElementTree import tostring
from xml.dom import minidom

# Import the converter classes
from convert_html_to_xml import BankStatement

app = Flask(__name__)
app.config['MAX_CONTENT_LENGTH'] = 10 * 1024 * 1024  # 10MB max file size

ALLOWED_EXTENSIONS = {'html', 'htm'}

def allowed_file(filename):
    """Check if file has allowed extension."""
    return '.' in filename and filename.rsplit('.', 1)[1].lower() in ALLOWED_EXTENSIONS

@app.route('/')
def index():
    """Serve the main HTML page."""
    html_path = Path(__file__).parent / 'index.html'
    with open(html_path, 'r', encoding='utf-8') as f:
        return f.read()

@app.route('/konverter.html')
def konverter():
    """Serve the converter HTML page."""
    html_path = Path(__file__).parent / 'konverter.html'
    with open(html_path, 'r', encoding='utf-8') as f:
        return f.read()

@app.route('/viewer.html')
def viewer():
    """Serve the viewer HTML page."""
    html_path = Path(__file__).parent / 'viewer.html'
    with open(html_path, 'r', encoding='utf-8') as f:
        return f.read()

@app.route('/convert', methods=['POST'])
def convert():
    """Handle file upload and conversion."""
    # Check if file is in request
    if 'html_file' not in request.files:
        return jsonify({'error': 'Fajl nije pronađen'}), 400

    file = request.files['html_file']

    # Check if file is selected
    if file.filename == '':
        return jsonify({'error': 'Niste odabrali fajl'}), 400

    # Check if file is allowed
    if not allowed_file(file.filename):
        return jsonify({'error': 'Nedozvoljen tip fajla. Dozvoljeni su samo .html i .htm fajlovi'}), 400

    try:
        # Create temporary files for input and output
        with tempfile.NamedTemporaryFile(mode='w', suffix='.html', delete=False, encoding='utf-8') as temp_html:
            # Read and save uploaded HTML
            html_content = file.read().decode('utf-8')
            temp_html.write(html_content)
            temp_html_path = temp_html.name

        # Create temp file for XML output
        temp_xml_fd, temp_xml_path = tempfile.mkstemp(suffix='.xml')
        os.close(temp_xml_fd)

        try:
            # Convert HTML to XML
            with open(temp_html_path, 'r', encoding='utf-8') as f:
                html_content = f.read()

            # Parse HTML and generate XML
            statement = BankStatement().parse_html(html_content)
            xml_root = statement.to_ibank_xml()

            # Convert to pretty XML string
            xml_string = tostring(xml_root, encoding='unicode')
            dom = minidom.parseString(xml_string)
            pretty_xml = dom.toprettyxml(indent='  ')
            pretty_xml = '\n'.join([line for line in pretty_xml.split('\n') if line.strip()])

            # Write XML to temp file
            with open(temp_xml_path, 'w', encoding='utf-8') as f:
                f.write(pretty_xml)

            # Generate output filename
            original_filename = secure_filename(file.filename)
            output_filename = original_filename.rsplit('.', 1)[0] + '.xml'

            # Send file
            response = send_file(
                temp_xml_path,
                mimetype='application/xml',
                as_attachment=True,
                download_name=output_filename
            )

            # Schedule cleanup after response is sent
            @response.call_on_close
            def cleanup():
                try:
                    os.unlink(temp_html_path)
                    os.unlink(temp_xml_path)
                except:
                    pass

            return response

        except Exception as e:
            # Clean up temp files on error
            try:
                os.unlink(temp_html_path)
                os.unlink(temp_xml_path)
            except:
                pass
            raise e

    except Exception as e:
        error_msg = str(e)
        if 'PREGLED SVIH VAŠIH TRANSAKCIJA' in error_msg or 'No transactions' in error_msg:
            return jsonify({'error': 'HTML fajl ne sadrži validne transakcije'}), 400
        else:
            return jsonify({'error': f'Greška pri konverziji: {error_msg}'}), 500

@app.route('/health')
def health():
    """Health check endpoint."""
    return jsonify({'status': 'ok'}), 200

if __name__ == '__main__':
    # Run the app
    app.run(host='0.0.0.0', port=5000, debug=False)

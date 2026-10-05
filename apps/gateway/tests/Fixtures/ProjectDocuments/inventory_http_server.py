"""Disposable S3 protocol fixture, with operator-controlled bucket state."""
import base64
import http.server
import json
import os
import sqlite3
import sys
import urllib.parse
import xml.etree.ElementTree as ET

input_path, output_path = sys.argv[1:3]
requests = []
list_count = 0


class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        global list_count
        with open(input_path) as file:
            state = json.load(file)
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(self.path).query)
        key = urllib.parse.unquote(urllib.parse.urlsplit(self.path).path).split('/', 2)[-1]
        listing = 'list-type' in query
        status = 200
        if listing:
            list_count += 1
            if state.get('mode') == 'database-change':
                with sqlite3.connect(os.path.dirname(input_path) + '/database.sqlite') as db:
                    db.execute('UPDATE project_document_entries SET revision = revision + 1')
            root = ET.Element('ListBucketResult', xmlns='http://s3.amazonaws.com/doc/2006-03-01/')
            keys = sorted(state['objects'])
            if state.get('mode') == 'changing-list' and list_count > 1:
                keys += ['new-object']
            token = query.get('continuation-token', ['0'])[0]
            offset = int(token.removeprefix('page+%2F-'))
            page = keys[offset:offset + 2]
            encoded = query.get('encoding-type') == ['url']
            if encoded and state.get('mode') != 'encoding-missing' and not (state.get('mode') == 'encoding-missing-page' and offset > 0):
                ET.SubElement(root, 'EncodingType').text = ('unsupported' if state.get('mode') == 'encoding-unsupported' else 'url')
            truncated = offset + 2 < len(keys)
            ET.SubElement(root, 'IsTruncated').text = 'true' if truncated else 'false'
            if truncated:
                next_token = str(offset + 2)
                if state.get('mode') in ('opaque-tokens', 'literal-plus'):
                    next_token = 'page+%2F-' + next_token
                ET.SubElement(root, 'NextContinuationToken').text = ('0' if state.get('mode') == 'repeated-token' else next_token)
            for name in page:
                obj = state['objects'].get(name, {'body': ''})
                row = ET.SubElement(root, 'Contents')
                key_text = urllib.parse.quote(name, safe='+' if state.get('mode') == 'literal-plus' else '') if encoded else name
                if state.get('mode') == 'encoding-malformed':
                    key_text = 'bad%GG'
                ET.SubElement(row, 'Key').text = key_text
                ET.SubElement(row, 'Size').text = str(len(base64.b64decode(obj['body'])))
                ET.SubElement(row, 'LastModified').text = '2026-10-01T00:00:00Z'
                ET.SubElement(row, 'ETag').text = (str(list_count) if state.get('mode') == 'changing-list' else obj.get('etag', 'stable-marker'))
            body = ET.tostring(root)
            if state.get('mode') == 'malformed-page':
                body = b'<ListBucketResult><IsTruncated>true</IsTruncated></ListBucketResult>'
        else:
            if key not in state['objects']:
                status = 404
                body = b'<Error><Code>NoSuchKey</Code></Error>'
            else:
                body = base64.b64decode(state['objects'][key]['body'])
        if state.get('mode') == ('list-500' if listing else 'get-500'):
            status = 500
            body = b'<Error><Code>InternalError</Code><Message>fixture-secret</Message></Error>'
        self.respond(status, body, listing)

    def respond(self, status, body, listing=False):
        requests.append({'method': self.command, 'path': self.path, 'listing': listing, 'status': status})
        with os.fdopen(os.open(output_path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600), 'w') as file:
            json.dump(requests, file)
        self.send_response(status)
        if status == 307:
            self.send_header('Location', '/fixture-bucket/redirect-target')
        self.send_header('Content-Type', 'application/xml' if listing or status != 200 else 'application/octet-stream')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_PUT(self):
        with open(input_path) as file:
            state = json.load(file)
        key = urllib.parse.unquote(urllib.parse.urlsplit(self.path).path).split('/', 2)[-1]
        body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
        state['objects'][key] = {'body': base64.b64encode(body).decode()}
        with open(input_path, 'w') as file:
            json.dump(state, file)
        self.respond(200, b'')

    def do_DELETE(self):
        with open(input_path) as file:
            state = json.load(file)
        mode = state.get('mode', '')
        if mode == 'delete-500':
            self.respond(500, b'<Error><Code>InternalError</Code><Message>fixture-secret</Message></Error>')
            return
        if mode == 'delete-redirect':
            self.respond(307, b'')
            return
        key = urllib.parse.unquote(urllib.parse.urlsplit(self.path).path).split('/', 2)[-1]
        state['objects'].pop(key, None)
        with open(input_path, 'w') as file:
            json.dump(state, file)
        self.respond(204, b'')

    def log_message(self, *args):
        pass


server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
print(server.server_address[1], flush=True)
server.serve_forever()

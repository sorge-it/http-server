// Writes the cases of GoNetHttpTest into cases.json: the bytes that the test files of Go send to a
// server or read as a request, then the probes the file had. Usage: extract <Go> <cases.json>,
// where <Go> holds src/ of Go.
package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"go/ast"
	"go/parser"
	"go/token"
	"log"
	"os"
	"path/filepath"
	"strconv"
	"strings"
)

type kase struct {
	Group               string `json:"group"`
	Name                string `json:"name"`
	Source              string `json:"source"`
	Rule                string `json:"rule,omitempty"`
	Request             any    `json:"request"`
	MaxHeaderBytes      int    `json:"maxHeaderBytes,omitempty"`
	MaxHeaderValueCount int    `json:"maxHeaderValueCount,omitempty"`
	MaxBody             *int   `json:"maxBody,omitempty"`
}

type file struct {
	path string
	set  *token.FileSet
	ast  *ast.File
}

var cases []kase

func main() {
	if len(os.Args) != 3 {
		log.Fatal("usage: extract <Go> <cases.json>")
	}
	open := func(path string) file {
		set := token.NewFileSet()
		f, err := parser.ParseFile(set, filepath.Join(os.Args[1], "src", path), nil, 0)
		if err != nil {
			log.Fatal(err)
		}
		return file{path, set, f}
	}
	serve := open("net/http/serve_test.go")
	request := open("net/http/request_test.go")
	readRequest := open("net/http/readrequest_test.go")
	transfer := open("net/http/transfer_test.go")
	chunked := open("net/http/internal/chunked_test.go")
	reader := open("net/textproto/reader_test.go")
	sniff := open("net/http/sniff_test.go")

	for _, e := range elements(table(serve.fn("TestServerValidatesHostHeader"))) {
		proto, host := str(field(e, 0, "proto")), str(field(e, 1, "host"))
		target := ""
		if strings.HasPrefix(proto, "HTTP/") {
			target = "GET / "
		}
		serve.add("host", "TestServerValidatesHostHeader", e, proto+" "+quote(host), target+proto+"\r\n"+host+"\r\n")
	}
	for _, e := range elements(table(serve.fn("TestServerValidatesHeaders"))) {
		header := str(field(e, 0, "header"))
		serve.add("fields", "TestServerValidatesHeaders", e, quote(header), "GET / HTTP/1.1\r\nHost: foo\r\n"+header+"\r\n")
	}
	for _, e := range elements(table(serve.fn("testUnsupportedTransferEncodingsReturn501"))) {
		serve.add("framing", "TestUnsupportedTransferEncodingsReturn501", e, "Transfer-Encoding "+str(e), "POST / HTTP/1.1\r\nConnection: close\r\nHost: localhost\r\nTransfer-Encoding: "+str(e)+"\r\n\r\n")
	}
	written := call(serve.fn("TestInvalidTrailerClosesConnection"), "WriteString").Args[0]
	serve.add("chunked", "TestInvalidTrailerClosesConnection", written, "invalid trailer, then a request", str(written))
	for _, e := range elements(table(serve.fn("TestInvalidChunkedBodies"))) {
		serve.add("lines", "TestInvalidChunkedBodies", e, str(field(e, 0, "name")), "POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n"+str(field(e, 1, "b")))
	}
	sent := assigned(serve.fn("TestTolerateCRLFBeforeRequestLine"))
	serve.add("lines", "TestTolerateCRLFBeforeRequestLine", sent, "CRLF CRLF after POST, then a request", str(sent))
	for _, e := range elements(table(serve.fn("TestServerValidatesMethod"))) {
		method := str(field(e, 0, "method"))
		serve.add("method", "TestServerValidatesMethod", e, "method "+quote(method), method+" / HTTP/1.1\r\nHost: foo.example\r\n\r\n")
	}
	for _, e := range elements(serve.value("serverExpectTests")) {
		length, expectation, readBody, chunked := 0, "", false, false
		if c, ok := e.(*ast.CallExpr); ok {
			length, expectation, readBody = num(c.Args[0]), str(c.Args[1]), eval(c.Args[2], nil).(bool)
		} else {
			for _, kv := range e.(*ast.CompositeLit).Elts {
				kv := kv.(*ast.KeyValueExpr)
				switch v := eval(kv.Value, nil); kv.Key.(*ast.Ident).Name {
				case "contentLength":
					length = v.(int)
				case "expectation":
					expectation = v.(string)
				case "readBody":
					readBody = v.(bool)
				case "chunked":
					chunked = v.(bool)
				}
			}
		}
		framing, body := fmt.Sprintf("Content-Length: %d", length), strings.Repeat("A", length)
		if chunked {
			framing, body = "Transfer-Encoding: chunked", "0\r\n\r\n"
		}
		name := fmt.Sprintf("Expect %s, %s, readbody=%v", quote(expectation), framing, readBody)
		serve.add("expect", "TestServerExpect", e, name, fmt.Sprintf("POST /?readbody=%v HTTP/1.1\r\nConnection: close\r\n%s\r\nExpect: %s\r\nHost: foo\r\n\r\n%s", readBody, framing, expectation, body))
	}

	for _, e := range elements(request.value("readRequestErrorTests")) {
		request.read("framing", "TestReadRequestErrors", field(e, 0, "in"))
	}
	for _, e := range elements(request.value("parseHTTPVersionTests")) {
		version := str(field(e, 0, "vers"))
		request.add("version", "TestParseHTTPVersion", e, "version "+quote(version), "GET / "+version+"\r\nHost: foo\r\n\r\n")
	}
	request.read("target", "TestStarRequest", call(request.fn("TestStarRequest"), "strings.NewReader").Args[0])
	for _, e := range elements(readRequest.value("reqTests")) {
		readRequest.read("target", "TestReadRequest", field(e, 0, "Raw"))
	}
	for _, e := range elements(readRequest.value("badRequestTests")) {
		readRequest.read("fields", "TestReadRequest_Bad", field(e, 1, "req"))
	}

	for _, e := range elements(table(transfer.fn("TestParseTransferEncoding"))) {
		fields := ""
		for _, kv := range field(e, 0, "hdr").(*ast.CompositeLit).Elts {
			kv := kv.(*ast.KeyValueExpr)
			for _, v := range kv.Value.(*ast.CompositeLit).Elts {
				fields += str(kv.Key) + ": " + str(v) + "\r\n"
			}
		}
		transfer.add("framing", "TestParseTransferEncoding", e, quote(fields), "POST / HTTP/1.1\r\nHost: foo\r\n"+fields+"\r\n0\r\n\r\n")
	}
	for _, e := range elements(table(transfer.fn("TestParseContentLength"))) {
		value := str(field(e, 0, "cl"))
		transfer.add("framing", "TestParseContentLength", e, "Content-Length "+quote(value), "POST / HTTP/1.1\r\nHost: foo\r\nContent-Length: "+value+"\r\n\r\n")
	}

	const post = "POST / HTTP/1.1\r\nHost: foo\r\nTransfer-Encoding: chunked\r\n\r\n"
	for _, e := range elements(table(chunked.fn("TestParseHexUint"))) {
		size := str(field(e, 0, "in"))
		chunked.add("chunked", "TestParseHexUint", e, "chunk size "+quote(size), post+size+"\r\na\r\n0\r\n\r\n")
	}
	in := assigned(chunked.fn("TestChunkReadingIgnoresExtensions"))
	chunked.add("chunked", "TestChunkReadingIgnoresExtensions", in, "chunk extensions", post+str(in))
	valid := constant(chunked.fn("TestIncompleteChunk"))
	for i := 0; i <= len(str(valid)); i++ {
		chunked.add("chunked", "TestIncompleteChunk", valid, "chunks "+quote(str(valid)[:i]), post+str(valid)[:i])
	}
	for _, e := range elements(table(chunked.fn("TestChunkInvalidInputs"))) {
		chunked.add("lines", "TestChunkInvalidInputs", e, str(field(e, 0, "name")), post+str(field(e, 1, "b")))
	}

	// The textproto cases are bare fields; a server reads them only in a request, and an HTTP/1.1
	// request without a Host gets 400 whatever its fields are.
	mime := func(fields string) string {
		return "GET / HTTP/1.1\r\n" + strings.TrimSuffix(fields, "\r\n") + "Host: foo\r\n\r\n"
	}
	for _, e := range elements(table(reader.fn("TestReadMIMEHeaderMalformed"))) {
		reader.add("fields", "TestReadMIMEHeaderMalformed", e, quote(str(e)), mime(str(e)))
	}
	ast.Inspect(reader.fn("TestReadMIMEHeaderBytes"), func(n ast.Node) bool {
		loop, ok := n.(*ast.ForStmt)
		if !ok {
			return true
		}
		s := loop.Body.List[0].(*ast.AssignStmt).Rhs[0]
		for i := num(loop.Init.(*ast.AssignStmt).Rhs[0]); i <= num(loop.Cond.(*ast.BinaryExpr).Y); i++ {
			fields := eval(s, map[string]any{"i": i}).(string)
			reader.add("bytes", "TestReadMIMEHeaderBytes", s, quote(fields), mime(fields))
		}
		return false
	})

	for _, e := range elements(sniff.value("sniffTests")) {
		data := str(field(e, 1, "data"))
		sniff.add("response", "TestDetectContentType", e, "sniff "+str(field(e, 0, "desc")), "GET /respond?body="+escape(data)+" HTTP/1.1\r\nHost: a\r\n\r\n")
	}

	write(os.Args[2], probes(os.Args[2]))
}

// read adds the bytes of a test of ReadRequest, which needs no Host; and for HTTP/1.1 without
// Host, as the server needs it, also with one.
func (f file) read(group, test string, e ast.Expr) {
	raw := str(e)
	f.add(group, test, e, quote(raw), raw)
	line, rest, found := strings.Cut(raw, "\r\n")
	head, _, _ := strings.Cut(rest, "\r\n\r\n")
	if found && strings.HasSuffix(line, " HTTP/1.1") && !strings.Contains(strings.ToLower("\r\n"+head), "\r\nhost:") {
		f.add(group, test, e, quote(raw)+" with Host", line+"\r\nHost: foo\r\n"+rest)
	}
}

func (f file) add(group, test string, at ast.Node, name, raw string) {
	source := fmt.Sprintf("%s:%d %s", f.path, f.set.Position(at.Pos()).Line, test)
	cases = append(cases, kase{Group: group, Name: name, Source: source, Request: encode(raw)})
}

func (f file) fn(name string) *ast.FuncDecl {
	for _, d := range f.ast.Decls {
		if fd, ok := d.(*ast.FuncDecl); ok && fd.Name.Name == name {
			return fd
		}
	}
	log.Fatalf("%s: no func %s", f.path, name)
	return nil
}

func (f file) value(name string) *ast.CompositeLit {
	for _, d := range f.ast.Decls {
		if gd, ok := d.(*ast.GenDecl); ok {
			for _, s := range gd.Specs {
				if vs, ok := s.(*ast.ValueSpec); ok && vs.Names[0].Name == name {
					return vs.Values[0].(*ast.CompositeLit)
				}
			}
		}
	}
	log.Fatalf("%s: no var %s", f.path, name)
	return nil
}

// table is the first list of the func written as a literal.
func table(fd *ast.FuncDecl) *ast.CompositeLit {
	var found *ast.CompositeLit
	ast.Inspect(fd, func(n ast.Node) bool {
		if c, ok := n.(*ast.CompositeLit); ok && found == nil {
			if _, ok := c.Type.(*ast.ArrayType); ok {
				found = c
			}
		}
		return found == nil
	})
	if found == nil {
		log.Fatalf("no table in %s", fd.Name.Name)
	}
	return found
}

func elements(c *ast.CompositeLit) []ast.Expr {
	var list []ast.Expr
	for _, e := range c.Elts {
		if kv, ok := e.(*ast.KeyValueExpr); ok {
			e = kv.Value
		}
		list = append(list, e)
	}
	return list
}

// field is the field of the name, or else at the index.
func field(e ast.Expr, index int, name string) ast.Expr {
	c := e.(*ast.CompositeLit)
	for _, f := range c.Elts {
		if kv, ok := f.(*ast.KeyValueExpr); ok {
			if kv.Key.(*ast.Ident).Name == name {
				return kv.Value
			}
		} else if index == 0 {
			return f
		} else {
			index--
		}
	}
	return &ast.BasicLit{ValuePos: c.Pos(), Kind: token.STRING, Value: `""`}
}

// call is the first call in the func of a method of the name, or of a func such as strings.Cut.
func call(fd *ast.FuncDecl, name string) *ast.CallExpr {
	var found *ast.CallExpr
	ast.Inspect(fd, func(n ast.Node) bool {
		if c, ok := n.(*ast.CallExpr); ok && found == nil {
			if s, ok := c.Fun.(*ast.SelectorExpr); ok && (s.Sel.Name == name || fmt.Sprintf("%v.%s", s.X, s.Sel.Name) == name) {
				found = c
			}
		}
		return found == nil
	})
	if found == nil {
		log.Fatalf("no call of %s in %s", name, fd.Name.Name)
	}
	return found
}

// assigned is the value of the first assignment of the func.
func assigned(fd *ast.FuncDecl) ast.Expr {
	for _, s := range fd.Body.List {
		if a, ok := s.(*ast.AssignStmt); ok {
			return a.Rhs[0]
		}
	}
	log.Fatalf("no assignment in %s", fd.Name.Name)
	return nil
}

// constant is the value of the first constant of the func.
func constant(fd *ast.FuncDecl) ast.Expr {
	for _, s := range fd.Body.List {
		if d, ok := s.(*ast.DeclStmt); ok {
			return d.Decl.(*ast.GenDecl).Specs[0].(*ast.ValueSpec).Values[0]
		}
	}
	log.Fatalf("no constant in %s", fd.Name.Name)
	return nil
}

func str(e ast.Expr) string { return eval(e, nil).(string) }

func num(e ast.Expr) int { return eval(e, nil).(int) }

// eval gives the value of an expression of constants, as Go does, with the variables.
func eval(e ast.Expr, vars map[string]any) any {
	switch e := e.(type) {
	case *ast.BasicLit:
		switch e.Kind {
		case token.STRING:
			s, err := strconv.Unquote(e.Value)
			if err != nil {
				log.Fatal(err)
			}
			return s
		case token.INT:
			n, err := strconv.ParseInt(e.Value, 0, 64)
			if err != nil {
				log.Fatal(err)
			}
			return int(n)
		}
	case *ast.ParenExpr:
		return eval(e.X, vars)
	case *ast.Ident:
		if v, ok := vars[e.Name]; ok {
			return v
		}
		switch e.Name {
		case "true":
			return true
		case "false":
			return false
		}
	case *ast.BinaryExpr:
		x, y := eval(e.X, vars), eval(e.Y, vars)
		switch e.Op {
		case token.ADD:
			if s, ok := x.(string); ok {
				return s + y.(string)
			}
			return x.(int) + y.(int)
		case token.SHL:
			return x.(int) << y.(int)
		}
	case *ast.CompositeLit:
		// A []byte of numbers.
		var b []byte
		for _, v := range e.Elts {
			b = append(b, byte(eval(v, vars).(int)))
		}
		return string(b)
	case *ast.CallExpr:
		args := make([]any, len(e.Args))
		for i, a := range e.Args {
			args[i] = eval(a, vars)
		}
		switch fun := e.Fun.(type) {
		case *ast.ArrayType:
			return args[0].(string)
		case *ast.Ident:
			switch fun.Name {
			case "string":
				if r, ok := args[0].(rune); ok {
					return string(r)
				}
				return args[0].(string)
			case "rune":
				return rune(args[0].(int))
			case "reqBytes":
				return strings.ReplaceAll(strings.TrimSpace(args[0].(string)), "\n", "\r\n") + "\r\n\r\n"
			}
		case *ast.SelectorExpr:
			if fun.Sel.Name == "Repeat" {
				return strings.Repeat(args[0].(string), args[1].(int))
			}
		}
	}
	log.Fatalf("no value for %#v", e)
	return nil
}

func quote(s string) string {
	q := strconv.Quote(s)
	if len(q) > 60 {
		return q[:57] + "..."
	}
	return q
}

// escape is rawurlencode() of PHP.
func escape(s string) string {
	var b strings.Builder
	for i := 0; i < len(s); i++ {
		c := s[i]
		if 'a' <= c && c <= 'z' || 'A' <= c && c <= 'Z' || '0' <= c && c <= '9' || strings.IndexByte("-_.~", c) >= 0 {
			b.WriteByte(c)
		} else {
			fmt.Fprintf(&b, "%%%02X", c)
		}
	}
	return b.String()
}

// encode gives the bytes as cases.json holds them: each byte as the code point of the same value,
// and a run of 64 bytes or more as {"repeat", "times"}.
func encode(raw string) any {
	var parts []any
	text := ""
	for i := 0; i < len(raw); {
		run := 1
		for i+run < len(raw) && raw[i+run] == raw[i] {
			run++
		}
		if run >= 64 {
			if text != "" {
				parts = append(parts, latin(text))
				text = ""
			}
			parts = append(parts, map[string]any{"repeat": latin(raw[i : i+1]), "times": run})
		} else {
			text += raw[i : i+run]
		}
		i += run
	}
	if text != "" || len(parts) == 0 {
		parts = append(parts, latin(text))
	}
	if len(parts) == 1 {
		if s, ok := parts[0].(string); ok {
			return s
		}
	}
	return parts
}

func latin(s string) string {
	r := make([]rune, len(s))
	for i := 0; i < len(s); i++ {
		r[i] = rune(s[i])
	}
	return string(r)
}

// decode is the reverse of encode.
func decode(v any) (string, error) {
	list, ok := v.([]any)
	if !ok {
		list = []any{v}
	}
	var b strings.Builder
	for _, part := range list {
		if m, ok := part.(map[string]any); ok {
			repeat, err := decode(m["repeat"])
			times, ok := m["times"].(float64)
			if err != nil || !ok || len(m) != 2 || times < 0 {
				return "", fmt.Errorf("bad repeat %v", m)
			}
			b.WriteString(strings.Repeat(repeat, int(times)))
			continue
		}
		s, ok := part.(string)
		if !ok {
			return "", fmt.Errorf("bad part %v", part)
		}
		for _, r := range s {
			if r > 0xFF {
				return "", fmt.Errorf("code point %U over U+00FF", r)
			}
			b.WriteByte(byte(r))
		}
	}
	return b.String(), nil
}

// probes are the cases of the file with the source "probe". A probe that misses a field, or has
// one that no case has, stops the program with its number.
func probes(path string) []kase {
	data, err := os.ReadFile(path)
	if err != nil {
		log.Fatal(err)
	}
	var all []json.RawMessage
	if err := json.Unmarshal(data, &all); err != nil {
		log.Fatalf("%s: %v", path, err)
	}
	var list []kase
	for i, raw := range all {
		var k kase
		strict := json.NewDecoder(bytes.NewReader(raw))
		strict.DisallowUnknownFields()
		if err := strict.Decode(&k); err != nil {
			log.Fatalf("%s: case %d: %v", path, i+1, err)
		}
		if k.Source != "probe" {
			continue
		}
		raw, err := decode(k.Request)
		if k.Group == "" || k.Name == "" || k.Rule == "" || err != nil {
			log.Fatalf("%s: case %d: a probe needs group, name, rule and request, a string or a list of strings and {\"repeat\", \"times\"}", path, i+1)
		}
		k.Request = encode(raw)
		list = append(list, k)
	}
	return list
}

// write puts the cases and the probes into the file, through a new file beside it, so that the
// file is never half written.
func write(path string, probes []kase) {
	var out bytes.Buffer
	out.WriteString("[\n")
	for i, c := range append(cases, probes...) {
		var line bytes.Buffer
		enc := json.NewEncoder(&line)
		enc.SetEscapeHTML(false)
		if err := enc.Encode(c); err != nil {
			log.Fatal(err)
		}
		if i > 0 {
			out.WriteString(",\n")
		}
		out.WriteString("    ")
		out.Write(bytes.TrimSuffix(line.Bytes(), []byte("\n")))
	}
	out.WriteString("\n]\n")
	temporary := path + ".new"
	if err := os.WriteFile(temporary, out.Bytes(), 0o644); err != nil {
		log.Fatal(err)
	}
	if err := os.Rename(temporary, path); err != nil {
		log.Fatal(err)
	}
}

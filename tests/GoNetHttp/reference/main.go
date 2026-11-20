// The reference of GoNetHttpTest: servers of net/http on loopback with the handler show, one for
// each set of limits and read size, as main describes.
package main

import (
	"bufio"
	"bytes"
	"cmp"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"testing/iotest"
	"time"
)

// latin gives each byte as the code point of the same value, as JSON holds no other byte.
func latin(s string) string {
	r := make([]rune, len(s))
	for i := 0; i < len(s); i++ {
		r[i] = rune(s[i])
	}
	return string(r)
}

type seen struct {
	Method    string              `json:"method"`
	Target    string              `json:"target"`
	Proto     string              `json:"proto"`
	Host      string              `json:"host"`
	Path      string              `json:"path"`
	RawPath   string              `json:"rawPath,omitempty"`
	RawQuery  string              `json:"rawQuery"`
	Header    map[string][]string `json:"header"`
	Body      string              `json:"body"`
	BodyError *string             `json:"bodyError,omitempty"`
}

// show answers with what the server read, in a field: the answer to HEAD has no body. The length
// keeps the answer the same for each method. For the query "deadline", the read of the body fails
// at once with the error of Go, which names both ends of the connection.
func show(w http.ResponseWriter, r *http.Request) {
	if r.URL.RawQuery == "deadline" {
		http.NewResponseController(w).SetReadDeadline(time.Now())
	}
	body, err := io.ReadAll(r.Body)
	s := seen{Method: latin(r.Method), Target: latin(r.RequestURI), Proto: latin(r.Proto), Host: latin(r.Host), Path: latin(r.URL.Path), RawPath: latin(r.URL.RawPath), RawQuery: latin(r.URL.RawQuery), Header: map[string][]string{}, Body: latin(string(body))}
	for name, values := range r.Header {
		for _, value := range values {
			s.Header[latin(name)] = append(s.Header[latin(name)], latin(value))
		}
	}
	if err != nil {
		e := latin(err.Error())
		s.BodyError = &e
	}
	j, _ := json.Marshal(s)
	w.Header().Set("Content-Length", "0")
	w.Header().Set("X-Seen", base64.StdEncoding.EncodeToString(j))
}

// pieces gives each read of a connection at most n bytes, as if the bytes came in pieces of n.
type pieces struct {
	net.Listener
	n int
}

type pieceConn struct {
	*net.TCPConn
	n int
}

func (l pieces) Accept() (net.Conn, error) {
	c, err := l.Listener.Accept()
	if err != nil || l.n == 0 {
		return c, err
	}
	return pieceConn{c.(*net.TCPConn), l.n}, nil
}

func (c pieceConn) Read(p []byte) (int, error) {
	return c.TCPConn.Read(p[:min(len(p), c.n)])
}

// limited is show: with maxBody 0 or more, as a handler of Go that reads the body through
// MaxBytesReader first.
func limited(maxBody int) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if maxBody < 0 {
			show(w, r)
			return
		}
		// Go has no limit of the body in its server. maxBody of the PHP server is what a handler
		// of Go does with MaxBytesReader: 413 for a body over it, else the handler as without.
		body, err := io.ReadAll(http.MaxBytesReader(w, r.Body, int64(maxBody)))
		var tooLarge *http.MaxBytesError
		if errors.As(err, &tooLarge) {
			http.Error(w, http.StatusText(http.StatusRequestEntityTooLarge), http.StatusRequestEntityTooLarge)
			return
		}
		r.Body = io.NopCloser(io.MultiReader(bytes.NewReader(body), iotest.ErrReader(cmp.Or(err, io.EOF))))
		show(w, r)
	}
}

func seconds(s float64) time.Duration {
	return time.Duration(s * float64(time.Second))
}

// main takes its orders on stdin, so one process serves each case of the PHP suite: "listen"
// starts a server with the settings of a case and gives its address; "close <address>" stops
// one. GoReference.php writes the orders.
func main() {
	servers := map[string]*http.Server{}
	lines := bufio.NewScanner(os.Stdin)
	for lines.Scan() {
		var command, address, host string
		var maxHeaderBytes, maxHeaderValueCount, maxBody, piece int
		var readHeaderTimeout, idleTimeout float64
		if _, err := fmt.Sscan(lines.Text(), &command); err != nil {
			log.Fatal(err)
		}
		switch command {
		case "close":
			fmt.Sscan(lines.Text(), &command, &address)
			servers[address].Close()
			delete(servers, address)
			fmt.Println("closed")
		default:
			if _, err := fmt.Sscan(lines.Text(), &command, &maxHeaderBytes, &maxHeaderValueCount, &maxBody, &readHeaderTimeout, &idleTimeout, &piece, &host); err != nil {
				log.Fatal(err)
			}
			ln, err := net.Listen("tcp", net.JoinHostPort(host, "0"))
			if err != nil {
				log.Fatal(err)
			}
			srv := &http.Server{Handler: limited(maxBody), MaxHeaderBytes: maxHeaderBytes, MaxHeaderValueCount: maxHeaderValueCount, ReadHeaderTimeout: seconds(readHeaderTimeout), IdleTimeout: seconds(idleTimeout), ErrorLog: log.New(io.Discard, "", 0)}
			servers[ln.Addr().String()] = srv
			go srv.Serve(pieces{ln, piece})
			fmt.Println(ln.Addr())
		}
	}
}

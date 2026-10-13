// tarscan — вспомогательная утилита: печатает записи tar-архива, пока это
// возможно, и сообщает, на какой записи и с какой ошибкой разбор прервался.
//
// usage: tarscan <file.tar>
package main

import (
	"archive/tar"
	"fmt"
	"io"
	"os"
)

func main() {
	if len(os.Args) != 2 {
		fmt.Fprintln(os.Stderr, "usage: tarscan <file.tar>")
		os.Exit(2)
	}
	f, err := os.Open(os.Args[1])
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
	defer f.Close()

	tr := tar.NewReader(f)
	i := 0
	for {
		h, err := tr.Next()
		if err == io.EOF {
			fmt.Printf("EOF after %d entries\n", i)
			return
		}
		if err != nil {
			fmt.Printf("ERROR after %d entries: %v\n", i, err)
			return
		}
		fmt.Printf("%3d type=%c name=%q size=%d mode=%o uid=%d gid=%d\n",
			i, h.Typeflag, h.Name, h.Size, h.Mode, h.Uid, h.Gid)
		i++
	}
}

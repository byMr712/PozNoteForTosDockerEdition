// modulemake собирает webui-модуль TerraMaster TOS из каталога с исходниками.
//
// Формат (разобран по эталонному модулю OutkastM):
//   <outRaw> = GNU-tar как есть, без каких-либо преобразований
// Итоговый webui.bz2 получается сжатием <outRaw> утилитой xz (magic FD 37 7A 58 5A 00).
//
// ВАЖНО: BOM и UTF-16 быть не должно. Распакованный payload эталона —
// обычный tar (payload[0..1] == "./", длина кратна 512), и TOS распаковывает
// его именно как tar. Прежние варианты (BOM + UTF-16LE побайтовым раздуванием
// и BOM + UTF-16LE через перекодировку UTF-8->UTF-16) TOS не принимал —
// модуль распаковывался в 0 файлов.
//
// Внутри tar имена записей имеют префикс "./" (как в эталоне), каталоги —
// с завершающим "/", владелец root:root, каталоги 0755, файлы 0644.
//
// usage: modulemake <srcDir> <outRaw>
package main

import (
	"archive/tar"
	"bytes"
	"io"
	"io/fs"
	"log"
	"os"
	"path/filepath"
	"sort"
	"time"
)

type entry struct {
	rel  string
	dir  bool
	mode int64
	abs  string
	size int64
}

func main() {
	if len(os.Args) != 3 {
		log.Fatal("usage: modulemake <srcDir> <outRaw>")
	}
	srcDir, err := filepath.Abs(os.Args[1])
	if err != nil {
		log.Fatal(err)
	}
	out := os.Args[2]

	var entries []entry

	err = filepath.WalkDir(srcDir, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if p == srcDir {
			return nil
		}
		rel, err := filepath.Rel(srcDir, p)
		if err != nil {
			return err
		}
		rel = filepath.ToSlash(rel)
		base := filepath.Base(p)
		if base == ".git" || base == ".DS_Store" || base == "Thumbs.db" || base == "Desktop.ini" {
			if d.IsDir() {
				return filepath.SkipDir
			}
			return nil
		}
		isDir := d.IsDir()
		var size int64
		if !isDir {
			info, err := d.Info()
			if err != nil {
				return err
			}
			size = info.Size()
		}
		mode := int64(0644)
		if isDir {
			mode = 0755
		}
		entries = append(entries, entry{rel: rel, dir: isDir, mode: mode, abs: p, size: size})
		return nil
	})
	if err != nil {
		log.Fatal(err)
	}

	sort.Slice(entries, func(i, j int) bool { return entries[i].rel < entries[j].rel })

	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)

	// Корневая запись "./" — как в эталонном модуле.
	root := &tar.Header{
		Name:     "./",
		Mode:     0755,
		Uid:      0,
		Gid:      0,
		Uname:    "root",
		Gname:    "root",
		ModTime:  time.Date(2017, 11, 1, 0, 0, 0, 0, time.UTC),
		Typeflag: tar.TypeDir,
		Format:   tar.FormatGNU,
	}
	if err := tw.WriteHeader(root); err != nil {
		log.Fatal(err)
	}

	mtime := time.Date(2017, 11, 1, 0, 0, 0, 0, time.UTC)
	for _, e := range entries {
		name := "./" + e.rel
		hdr := &tar.Header{
			Name:    name,
			Mode:    e.mode,
			Uid:     0,
			Gid:     0,
			Uname:   "root",
			Gname:   "root",
			ModTime: mtime,
			Format:  tar.FormatGNU,
		}
		if e.dir {
			hdr.Typeflag = tar.TypeDir
			hdr.Name = name + "/"
			if err := tw.WriteHeader(hdr); err != nil {
				log.Fatal(err)
			}
			continue
		}
		hdr.Typeflag = tar.TypeReg
		hdr.Size = e.size
		if err := tw.WriteHeader(hdr); err != nil {
			log.Fatal(err)
		}
		in, err := os.Open(e.abs)
		if err != nil {
			log.Fatal(err)
		}
		if _, err := io.Copy(tw, in); err != nil {
			in.Close()
			log.Fatal(err)
		}
		in.Close()
	}
	if err := tw.Close(); err != nil {
		log.Fatal(err)
	}

	raw := buf.Bytes()

	f, err := os.Create(out)
	if err != nil {
		log.Fatal(err)
	}
	defer f.Close()

	// Пишем tar как есть — TOS ожидает именно tar (без BOM/UTF-16).
	if _, err := f.Write(raw); err != nil {
		log.Fatal(err)
	}

	log.Printf("wrote %s (tar %d bytes, %d entries)",
		out, len(raw), len(entries)+1)
}

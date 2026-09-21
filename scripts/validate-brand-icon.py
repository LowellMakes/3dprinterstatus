#!/usr/bin/env python3
"""Validate cached brand SVGs against a small, inert subset."""

from __future__ import annotations

import os
import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

MAX_BYTES = 131_072
ALLOWED_ELEMENTS = {
    "svg",
    "g",
    "path",
    "circle",
    "ellipse",
    "line",
    "polygon",
    "polyline",
    "rect",
}
ALLOWED_ATTRIBUTES = {
    "aria-label",
    "clip-rule",
    "cx",
    "cy",
    "d",
    "fill",
    "fill-opacity",
    "fill-rule",
    "height",
    "opacity",
    "points",
    "preserveAspectRatio",
    "r",
    "role",
    "rx",
    "ry",
    "stroke",
    "stroke-dasharray",
    "stroke-dashoffset",
    "stroke-linecap",
    "stroke-linejoin",
    "stroke-miterlimit",
    "stroke-opacity",
    "stroke-width",
    "transform",
    "viewBox",
    "width",
    "x",
    "x1",
    "x2",
    "y",
    "y1",
    "y2",
}
FORBIDDEN_RAW = (
    b"<!doctype",
    b"<!entity",
    b"<?xml-stylesheet",
    b"<script",
    b"<style",
    b"javascript:",
    b"@import",
    b"url(",
)


def local_name(name: str) -> str:
    return name.rsplit("}", 1)[-1]


def validate(path: Path) -> None:
    size = os.path.getsize(path)
    if size == 0 or size > MAX_BYTES:
        raise ValueError(f"SVG size must be between 1 and {MAX_BYTES} bytes")

    raw = path.read_bytes()
    lowered = raw.lower()
    if not raw.lstrip().startswith(b"<svg"):
        raise ValueError("response is not an SVG")
    for forbidden in FORBIDDEN_RAW:
        if forbidden in lowered:
            raise ValueError(f"unsafe SVG content: {forbidden.decode()}")
    if re.search(rb"\son[a-z]+\s*=", lowered):
        raise ValueError("event handler found in SVG")

    root = ET.fromstring(raw)
    if local_name(root.tag) != "svg":
        raise ValueError("unexpected SVG root element")

    for element in root.iter():
        tag = local_name(element.tag)
        if tag not in ALLOWED_ELEMENTS:
            raise ValueError(f"SVG element is not allowlisted: {tag}")
        for raw_name, value in element.attrib.items():
            name = local_name(raw_name)
            if name not in ALLOWED_ATTRIBUTES:
                raise ValueError(f"SVG attribute is not allowlisted: {name}")
            lowered_value = value.lower()
            if name in {"href", "src"} or "url(" in lowered_value or "@import" in lowered_value:
                raise ValueError(f"external SVG reference is not allowed: {name}")


def main() -> int:
    if len(sys.argv) != 2:
        print(f"Usage: {Path(sys.argv[0]).name} FILE.svg", file=sys.stderr)
        return 2
    try:
        validate(Path(sys.argv[1]))
    except (OSError, ET.ParseError, ValueError) as error:
        print(f"Invalid brand icon: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

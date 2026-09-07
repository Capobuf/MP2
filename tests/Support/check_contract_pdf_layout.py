import sys

from weasyprint import HTML
from weasyprint.formatting_structure.boxes import TableBox, TableCellBox, TextBox


document = HTML(string=sys.stdin.read()).render()
supplier_word_found = False
table_found = False

for page in document.pages:
    root = page._page_box
    page_left = root.content_box_x()
    page_right = page_left + root.width
    for table in root.descendants():
        if not isinstance(table, TableBox) or table.element.get('class') != 'contracts':
            continue
        table_found = True
        assert table.position_x >= page_left - 0.5, 'Table exceeds the left margin'
        assert table.position_x + table.border_width() <= page_right + 0.5, 'Table exceeds the right margin'
        for cell in table.descendants():
            if not isinstance(cell, TableCellBox):
                continue
            left = cell.content_box_x()
            right = left + cell.width
            for text in cell.descendants():
                if not isinstance(text, TextBox):
                    continue
                assert text.position_x >= left - 0.5, 'Text overlaps the preceding column'
                assert text.position_x + text.width <= right + 0.5, 'Text exceeds its column'
                assert text.position_x + text.width <= page_right - 3, 'Text touches the right edge'
                if 'FornitoreSpecializzato' in text.text:
                    supplier_word_found = True

assert table_found, 'Contract register is missing'
assert supplier_word_found, 'Supplier word is unnecessarily split'

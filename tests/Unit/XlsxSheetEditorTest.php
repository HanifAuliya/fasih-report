<?php

namespace Tests\Unit;

use App\Support\XlsxSheetEditor;
use PHPUnit\Framework\TestCase;

class XlsxSheetEditorTest extends TestCase
{
    private const SHEETS = '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>';

    public function test_empty_defined_names_from_openpyxl_is_filled_not_duplicated(): void
    {
        $workbook = '<workbook>'.self::SHEETS.'<definedNames/><calcPr calcId="162913"/></workbook>';

        $result = XlsxSheetEditor::filterDefinedNames($workbook, ['Sheet1' => 'A1:Z472']);

        $this->assertSame(1, substr_count($result, '<definedNames'), 'Excel menganggap rusak bila ada dua definedNames');
        $this->assertStringContainsString(
            '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'Sheet1\'!$A$1:$Z$472</definedName></definedNames><calcPr',
            $result,
        );
    }

    public function test_defined_names_block_is_added_when_missing(): void
    {
        $result = XlsxSheetEditor::filterDefinedNames('<workbook>'.self::SHEETS.'<calcPr/></workbook>', ['Sheet1' => 'A1:C3']);

        $this->assertSame(1, substr_count($result, '<definedNames>'));
        $this->assertStringContainsString('</sheets><definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'Sheet1\'!$A$1:$C$3</definedName></definedNames>', $result);
    }

    public function test_existing_filter_range_is_replaced(): void
    {
        $workbook = '<workbook>'.self::SHEETS.'<definedNames><definedName hidden="1" localSheetId="0" name="_xlnm._FilterDatabase">\'Sheet1\'!$A$1:$B$2</definedName></definedNames></workbook>';

        $result = XlsxSheetEditor::filterDefinedNames($workbook, ['Sheet1' => 'A1:D9']);

        $this->assertSame(1, substr_count($result, '_xlnm._FilterDatabase'));
        $this->assertStringContainsString('$A$1:$D$9', $result);
    }
}

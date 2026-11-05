/**
 * Deploy as a web app (execute as you, access for anyone with the URL).
 * Put the same random value in Script Properties as DUE_FLOW_SECRET.
 */
function doPost(e) {
  var body = e.postData.contents;
  var secret = PropertiesService.getScriptProperties().getProperty('DUE_FLOW_SECRET');
  var expected = bytesToHex(Utilities.computeHmacSha256Signature(body, secret));
  if (!secret || !constantTimeEqual(expected, e.parameter.signature)) {
    return output({ok: false, error: 'invalid signature'});
  }
  var item = JSON.parse(body);
  var payload = item.payload || {};
  var sheetName = item.entity_type === 'transaction' ? 'Transactions' :
    item.entity_type === 'weekly_report' ? 'Weekly Reports' :
    item.entity_type === 'performance' ? 'Customer Performance' : 'Customers';
  var idKey = item.entity_type === 'transaction' ? 'transaction_id' :
    item.entity_type === 'weekly_report' ? 'week_start' :
    item.entity_type === 'performance' ? 'id' : 'customer_id';
  upsert(sheetName, idKey, payload);
  return output({ok: true, id: item.id});
}

function upsert(sheetName, idKey, payload) {
  var ss = SpreadsheetApp.getActive();
  var sheet = ss.getSheetByName(sheetName) || ss.insertSheet(sheetName);
  var keys = Object.keys(payload);
  if (sheet.getLastRow() === 0) sheet.appendRow(keys);
  var headers = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];
  keys.forEach(function (key) { if (headers.indexOf(key) < 0) { sheet.getRange(1, sheet.getLastColumn() + 1).setValue(key); headers.push(key); } });
  var idColumn = headers.indexOf(idKey) + 1;
  var values = sheet.getRange(2, idColumn, Math.max(sheet.getLastRow() - 1, 1), 1).getValues();
  var row = -1;
  for (var i = 0; i < values.length; i++) if (String(values[i][0]) === String(payload[idKey])) { row = i + 2; break; }
  var record = headers.map(function (header) { return payload[header] === undefined ? '' : payload[header]; });
  if (row < 0) sheet.appendRow(record); else sheet.getRange(row, 1, 1, record.length).setValues([record]);
}

function bytesToHex(bytes) {
  return bytes.map(function (b) { return ('0' + (b < 0 ? b + 256 : b).toString(16)).slice(-2); }).join('');
}
function constantTimeEqual(a, b) {
  if (!a || !b || a.length !== b.length) return false;
  var result = 0; for (var i = 0; i < a.length; i++) result |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return result === 0;
}
function output(value) {
  return ContentService.createTextOutput(JSON.stringify(value)).setMimeType(ContentService.MimeType.JSON);
}

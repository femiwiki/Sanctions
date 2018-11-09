( function( mw, $ ) {
	// 토론 주제 아래 도움말 표시
	var message = '<pre><strong>도움말</strong>' +
		'다음과 같이 쓴 경우에 집계됩니다:' +
		'<code>{{찬성|3}}</code>, <code>{{찬성}}</code>, <code>{{반대}}</code>' +
		'부적절한 사용자명 변경 건의에는 <code>{{찬성}}</code>, <code>{{반대}}</code>만을 사용할 수 있습니다.</pre>';
	$(' .flow-board' ).append( message );
} )( mediaWiki, jQuery );

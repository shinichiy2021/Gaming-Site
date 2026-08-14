import { useEffect } from 'react';
import {
	FLOW_CONNECTIONS_DUAL,
	FLOW_CONNECTIONS_SINGLE,
	homeOutput,
	hvInput,
	proGridCharge,
	solarToDelta,
	upsOutput,
} from './constants';

function wattsForFlow( flowId, status ) {
	if ( ! status ) {
		return 0;
	}

	if ( flowId === 'solar' ) {
		return solarToDelta( status );
	}

	if ( flowId === 'grid' ) {
		const grid = proGridCharge( status );
		return grid.active ? grid.watts : 0;
	}

	if ( flowId === 'hv' ) {
		return hvInput( status );
	}

	if ( flowId === 'proToDelta' || flowId === 'proToLink' || flowId === 'linkToDelta' ) {
		return 0;
	}

	if ( flowId === 'proToHome' || flowId === 'home' ) {
		return homeOutput( status );
	}

	if ( flowId === 'deltaToUps' || flowId === 'ups' ) {
		return upsOutput( status );
	}

	return 0;
}

function anchorRect( mapEl, anchorId ) {
	const el = mapEl.querySelector( `[data-flow-id="${ anchorId }"]` );
	if ( ! el ) {
		return null;
	}

	const mapRect = mapEl.getBoundingClientRect();
	const rect = el.getBoundingClientRect();

	return {
		left: rect.left - mapRect.left,
		right: rect.right - mapRect.left,
		top: rect.top - mapRect.top,
		bottom: rect.bottom - mapRect.top,
		cx: rect.left - mapRect.left + rect.width / 2,
		cy: rect.top - mapRect.top + rect.height / 2,
	};
}

function resolvePoint( rect, side ) {
	if ( ! rect ) {
		return null;
	}

	switch ( side ) {
		case 'left':
			return { x: rect.left, y: rect.cy };
		case 'right':
			return { x: rect.right, y: rect.cy };
		case 'top':
			return { x: rect.cx, y: rect.top };
		case 'bottom':
			return { x: rect.cx, y: rect.bottom };
		default:
			return { x: rect.cx, y: rect.cy };
	}
}

function resolveConnection( mapEl, connection ) {
	const fromRect = anchorRect( mapEl, connection.from.id );
	const toRect = anchorRect( mapEl, connection.to.id );

	if ( ! fromRect || ! toRect ) {
		return null;
	}

	let from = resolvePoint( fromRect, connection.from.side );
	let to = resolvePoint( toRect, connection.to.side );

	if ( connection.axis === 'horizontal' ) {
		const y = ( fromRect.cy + toRect.cy ) / 2;
		from = { x: from.x, y };
		to = { x: to.x, y };
	} else if ( connection.axis === 'vertical' ) {
		const x = connection.align === 'from'
			? fromRect.cx
			: connection.align === 'to'
				? toRect.cx
				: ( fromRect.cx + toRect.cx ) / 2;
		from = { x, y: from.y };
		to = { x, y: to.y };
	}

	const inset = 6;
	const dx = to.x - from.x;
	const dy = to.y - from.y;
	const length = Math.hypot( dx, dy ) || 1;
	from = {
		x: from.x + ( dx / length ) * inset,
		y: from.y + ( dy / length ) * inset,
	};
	to = {
		x: to.x - ( dx / length ) * inset,
		y: to.y - ( dy / length ) * inset,
	};

	return {
		id: connection.id,
		from,
		to,
		color: connection.color,
		axis: connection.axis,
		showLabel: !! connection.showLabel,
		alwaysLabel: !! connection.alwaysLabel,
	};
}

function resolvePaths( mapEl, status ) {
	const connections = status?.dual ? FLOW_CONNECTIONS_DUAL : FLOW_CONNECTIONS_SINGLE;

	return connections
		.map( ( connection ) => resolveConnection( mapEl, connection ) )
		.filter( Boolean );
}

function drawWattsLabel( ctx, from, to, watts, color, axis ) {
	const text = `${ Math.round( watts ).toLocaleString() } W`;
	let mx = ( from.x + to.x ) / 2;
	let my = ( from.y + to.y ) / 2;

	if ( axis === 'vertical' ) {
		mx += 42;
	}

	ctx.font = '700 17px Inter, sans-serif';
	const width = ctx.measureText( text ).width;
	const padX = 11;
	const height = 27;
	const boxW = width + padX * 2;
	const boxH = height;
	const x = mx - boxW / 2;
	const y = my - boxH / 2;

	ctx.beginPath();
	if ( typeof ctx.roundRect === 'function' ) {
		ctx.roundRect( x, y, boxW, boxH, 4 );
	} else {
		ctx.rect( x, y, boxW, boxH );
	}
	ctx.fillStyle = 'rgba(8, 7, 15, 0.92)';
	ctx.fill();
	ctx.lineWidth = 1;
	ctx.strokeStyle = color;
	ctx.stroke();

	ctx.fillStyle = color;
	ctx.textAlign = 'center';
	ctx.textBaseline = 'middle';
	ctx.fillText( text, mx, my + 0.5 );
}

function drawPath( ctx, path, watts ) {
	if ( ! path.showLabel && ! path.alwaysLabel ) {
		return;
	}

	drawWattsLabel( ctx, path.from, path.to, watts, path.color, path.axis );
}

export function useFlowCanvas( canvasRef, mapRef, status ) {
	useEffect( () => {
		const canvas = canvasRef.current;
		const mapEl = mapRef.current;

		if ( ! canvas || ! mapEl ) {
			return undefined;
		}

		const ctx = canvas.getContext( '2d', { alpha: true } );

		const paint = () => {
			const width = mapEl.clientWidth;
			const height = mapEl.clientHeight;
			const dpr = Math.min( window.devicePixelRatio || 1, 2 );

			canvas.width = Math.round( width * dpr );
			canvas.height = Math.round( height * dpr );
			canvas.style.width = `${ width }px`;
			canvas.style.height = `${ height }px`;
			ctx.setTransform( dpr, 0, 0, dpr, 0, 0 );
			ctx.clearRect( 0, 0, width, height );

			resolvePaths( mapEl, status ).forEach( ( path ) => {
				drawPath( ctx, path, wattsForFlow( path.id, status ) );
			} );
		};

		const observer = typeof ResizeObserver !== 'undefined'
			? new ResizeObserver( paint )
			: null;

		paint();
		window.addEventListener( 'resize', paint );
		observer?.observe( mapEl );

		return () => {
			window.removeEventListener( 'resize', paint );
			observer?.disconnect();
		};
	}, [ canvasRef, mapRef, status ] );
}

import { getFilterState, destroyFilterState } from './filter-state';

const STORAGE_KEY = 'data_machine_events_calendar_state';

describe( 'stored calendar filters', () => {
	let calendar: HTMLElement;

	beforeEach( () => {
		window.history.replaceState( {}, '', '/events/?paged=3' );
		window.localStorage.clear();
		document.body.innerHTML =
			'<div class="data-machine-events-calendar" data-filter-persistence="1"></div>';
		calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
	} );

	afterEach( () => {
		destroyFilterState( calendar );
	} );

	it( 'replaces the stale server page with the restored filtered URL', () => {
		window.localStorage.setItem(
			STORAGE_KEY,
			JSON.stringify( { 'tax_filter[venue][]': [ '42' ] } )
		);
		const navigate = jest.fn();

		expect(
			getFilterState( calendar ).restoreFromStorage( navigate )
		).toBe( true );
		expect( navigate ).toHaveBeenCalledWith(
			'/events/?tax_filter%5Bvenue%5D%5B%5D=42'
		);
		expect( window.location.search ).toBe( '?paged=3' );
	} );

	it( 'does nothing when there are no stored filters', () => {
		const navigate = jest.fn();

		expect(
			getFilterState( calendar ).restoreFromStorage( navigate )
		).toBe( false );
		expect( navigate ).not.toHaveBeenCalled();
	} );

	it.each( [
		[ 'disabled filter UI', '0', '' ],
		[ 'consumer-scoped embed', '1', 'signed-scope' ],
	] )(
		'does not restore into a %s',
		( _context, persistence, scopeToken ) => {
			calendar.dataset.filterPersistence = persistence;
			if ( scopeToken ) {
				calendar.dataset.scopeToken = scopeToken;
			}
			window.localStorage.setItem(
				STORAGE_KEY,
				JSON.stringify( { 'tax_filter[venue][]': [ '42' ] } )
			);
			const navigate = jest.fn();

			expect(
				getFilterState( calendar ).restoreFromStorage( navigate )
			).toBe( false );
			expect( navigate ).not.toHaveBeenCalled();
		}
	);

	it( 'preserves explicit URL filters instead of restoring stored ones', () => {
		window.history.replaceState(
			{},
			'',
			'/events/?tax_filter%5Bfestival%5D%5B%5D=7'
		);
		window.localStorage.setItem(
			STORAGE_KEY,
			JSON.stringify( { 'tax_filter[venue][]': [ '42' ] } )
		);
		const navigate = jest.fn();

		expect(
			getFilterState( calendar ).restoreFromStorage( navigate )
		).toBe( false );
		expect( navigate ).not.toHaveBeenCalled();
	} );
} );
